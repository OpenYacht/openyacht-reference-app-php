<?php

namespace App\Services\Federation;

use App\Models\FederationPartner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP to a partner's federation API, signed with this node's
 * active key (FP-6). Always HTTPS with strict TLS verification (FP-2).
 *
 * // federation-protocol.md §Request Signing
 */
class SignedClient
{
    public function __construct(private Signer $signer, private OutboundUrlGuard $guard) {}

    public function get(FederationPartner $partner, string $pathWithQuery): Response
    {
        $this->guard->assertPublicHost($partner->domain);

        return $this->request()
            ->withHeaders($this->signer->headers('GET', $pathWithQuery, $partner->domain))
            ->get("https://{$partner->domain}{$pathWithQuery}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(FederationPartner $partner, string $pathWithQuery, array $payload): Response
    {
        $this->guard->assertPublicHost($partner->domain);

        $rawBody = self::encode($payload);

        return $this->request()
            ->withHeaders($this->signer->headers('POST', $pathWithQuery, $partner->domain, $rawBody))
            ->withBody($rawBody, 'application/json')
            ->post("https://{$partner->domain}{$pathWithQuery}");
    }

    public function delete(FederationPartner $partner, string $pathWithQuery): Response
    {
        $this->guard->assertPublicHost($partner->domain);

        return $this->request()
            ->withHeaders($this->signer->headers('DELETE', $pathWithQuery, $partner->domain))
            ->delete("https://{$partner->domain}{$pathWithQuery}");
    }

    /**
     * A signed POST to a subscription callback — an absolute URL the
     * partner registered rather than a path on its identity domain. The
     * signing string is built from the callback's own host and path, so
     * the receiver verifies it exactly like any federation request
     * (API-10). The URL was guard-checked at registration; it is checked
     * again here because a delivery may run long after.
     *
     * // api-design.md §Subscriptions
     *
     * @param  array<string, mixed>  $payload
     */
    public function postCallback(string $callbackUrl, array $payload): Response
    {
        $this->guard->assertPublicHttpsUrl($callbackUrl);

        $parts = parse_url($callbackUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $pathWithQuery = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $rawBody = self::encode($payload);

        return $this->request()
            ->withHeaders($this->signer->headers('POST', $pathWithQuery, $host, $rawBody))
            ->withBody($rawBody, 'application/json')
            ->post($callbackUrl);
    }

    private function request(): PendingRequest
    {
        return Http::timeout(30)->withoutRedirecting();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
