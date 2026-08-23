<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses federation routes on any host other than the identity domain, so
 * the node's identity cannot fork. With no identity domain configured, the
 * node is not yet installed and federation is unavailable everywhere.
 *
 * // federation-protocol.md §Choosing the identity domain
 * // identity-domain-hosting.md §What the intranet adapter already implements
 */
class EnsureIdentityDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $identityDomain = config('openyacht.domain');

        if (! is_string($identityDomain) || $identityDomain === '') {
            abort(404);
        }

        if (strtolower($request->getHost()) !== strtolower($identityDomain)) {
            abort(404);
        }

        return $next($request);
    }
}
