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
            $origin = $request->header('Origin') ?? $request->header('Referer');

            if ($origin !== null) {
                $allowed = collect($domains)->contains(
                    fn (string $domain): bool => str_contains($origin, $domain),
                );

                if (! $allowed) {
                    return response()->json([
                        'error' => 'API key is not authorized for this domain.',
                    ], 403);
                }
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
