<?php

namespace App\Services\Federation;

use App\Models\FederationPartner;
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
    public function __construct(private Signer $signer) {}

    public function get(FederationPartner $partner, string $pathWithQuery): Response
    {
        return Http::timeout(30)
            ->withHeaders($this->signer->headers('GET', $pathWithQuery, $partner->domain))
            ->get("https://{$partner->domain}{$pathWithQuery}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(FederationPartner $partner, string $pathWithQuery, array $payload): Response
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Http::timeout(30)
            ->withHeaders($this->signer->headers('POST', $pathWithQuery, $partner->domain, $rawBody))
            ->withBody($rawBody, 'application/json')
            ->post("https://{$partner->domain}{$pathWithQuery}");
    }
}
