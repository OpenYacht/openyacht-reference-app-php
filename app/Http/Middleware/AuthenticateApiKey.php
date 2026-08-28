<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates internal-API requests by key, enforcing scopes, optional
 * domain restriction, and the per-key rate limit. Modelled on a production
 * intranet's proven pattern; keys here are stored hashed.
 */
class AuthenticateApiKey
{
    /**
     * @param  string  ...$scopes  Required scopes for this route group
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $plaintext = $request->header('X-API-Key')
            ?? $request->bearerToken()
            ?? $request->query('api_key');

        if (! is_string($plaintext) || $plaintext === '') {
            return response()->json([
                'error' => 'API key is required. Provide via X-API-Key header, Authorization: Bearer header, or api_key query parameter.',
            ], 401);
        }

        $apiKey = ApiKey::findByPlaintext($plaintext);

        if ($apiKey === null || ! $apiKey->is_active) {
            return response()->json([
                'error' => 'Invalid or inactive API key.',
            ], 401);
        }

        $domains = $apiKey->domains ?? [];

        if ($domains !== []) {
            // A domain allowlist is an access boundary, so a request that
            // carries no Origin/Referer (any non-browser client) must be
            // rejected, not waved through — otherwise the restriction only
            // constrains browsers and a leaked key is used freely. Match
            // on the parsed host with exact or dot-suffix equality, never a
            // substring (which `partner.example.attacker.com` would pass).
            $origin = $request->header('Origin') ?? $request->header('Referer');
            $host = strtolower((string) parse_url((string) $origin, PHP_URL_HOST));

            $allowed = $host !== '' && collect($domains)->contains(function (string $domain) use ($host): bool {
                $domain = strtolower($domain);

                return $host === $domain || str_ends_with($host, '.'.$domain);
            });

            if (! $allowed) {
                return response()->json([
                    'error' => 'API key is not authorized for this domain.',
                ], 403);
            }
        }

        foreach ($scopes as $scope) {
            if (! $apiKey->hasScope($scope)) {
                return response()->json([
                    'error' => "API key does not have the required scope: {$scope}.",
                ], 403);
            }
        }

        $rateLimitKey = 'api_key:'.$apiKey->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $apiKey->rate_limit)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response()
                ->json(['error' => 'Rate limit exceeded. Try again later.', 'retry_after' => $retryAfter], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $apiKey->touchLastUsed();

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
