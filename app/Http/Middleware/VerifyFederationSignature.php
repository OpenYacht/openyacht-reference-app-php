<?php

namespace App\Http\Middleware;

use App\Enums\FederationErrorCode;
use App\Enums\TrustLevel;
use App\Http\Responses\FederationErrorResponse;
use App\Models\FederationPartner;
use App\Services\Federation\FederationNotifier;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use App\Services\Federation\VerificationResult;
use App\Services\Federation\Verifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound federation requests, implementing the
 * verification procedure end to end: timestamp window, blocked-partner
 * rejection, key selection with pinning, signature verification with the
 * one permitted well-known refetch, and node-UUID-change detection.
 * First contact from an unknown domain becomes a provisional partner;
 * only verified partners may read listings.
 *
 * // federation-protocol.md §Request Signing — Verification procedure
 */
class VerifyFederationSignature
{
    public function __construct(
        private Verifier $verifier,
        private PartnerService $partners,
        private FederationNotifier $notifier,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $senderDomain = strtolower((string) $request->header('X-OpenYacht-Node'));
        $keyId = (string) $request->header('X-OpenYacht-Key');
        $timestamp = (string) $request->header('X-OpenYacht-Timestamp');
        $signature = (string) $request->header('X-OpenYacht-Signature');

        if ($senderDomain === '' || $keyId === '' || $timestamp === '' || $signature === '') {
            return $this->reject($request, $senderDomain, FederationErrorCode::SignatureInvalid, 'Missing X-OpenYacht signature headers.');
        }

        // First contact from an unknown domain is trusted-on-first-use as
        // provisional (FP-13); an unreachable well-known document means we
        // cannot authenticate the sender at all.
        $partner = FederationPartner::query()->where('domain', $senderDomain)->first();

        if ($partner === null) {
            try {
                $partner = $this->partners->add($senderDomain);
            } catch (InvalidWellKnownDocument) {
                return $this->reject($request, $senderDomain, FederationErrorCode::PartnerUnknown, "Could not fetch the sender's well-known document.");
            }

            // Unlike an operator-initiated add, this is an unsolicited
            // introduction — the one worth emailing about, since a
            // provisional partner nobody notices means nobody federating.
            $this->notifier->partnerFirstContact($partner);
        }

        if ($partner->trust_level === TrustLevel::Blocked) {
            return $this->reject($request, $senderDomain, FederationErrorCode::PartnerBlocked, 'This partner is blocked.');
        }

        // A pinned key is the only acceptable key until an administrator
        // confirms otherwise, even if the well-known document serves more
        // (FP-12). The confirmation is the explicit admin key-refresh
        // action (PartnerService::refreshKeys with a confirming user);
        // the automatic refetch below never moves the pin.
        if ($partner->pinned_key_id !== null && $keyId !== $partner->pinned_key_id) {
            return $this->reject($request, $senderDomain, FederationErrorCode::SignatureInvalid, 'The presented key is not the pinned key for this partner.');
        }

        $result = $this->verify($request, $keyId, $timestamp, $signature, $partner->publishedKeys());

        if (! $result->verified && $result->error === FederationErrorCode::TimestampOutOfRange) {
            return $this->reject($request, $senderDomain, FederationErrorCode::TimestampOutOfRange, 'The request timestamp is outside the accepted window.');
        }

        if (! $result->verified) {
            // On failure: refetch the sender's well-known document once
            // (fresh, rate-limit-respecting) and retry (FP-10). A changed
            // node UUID downgrades the partner and rejects (FP-11).
            $previousUuid = $partner->node_uuid;

            if (! RateLimiter::attempt("openyacht:refetch:{$senderDomain}", 1, fn () => true, 60)) {
                return $this->reject($request, $senderDomain, FederationErrorCode::SignatureInvalid, 'Signature verification failed.');
            }

            try {
                $partner = $this->partners->refreshKeys($partner);
            } catch (InvalidWellKnownDocument) {
                return $this->reject($request, $senderDomain, FederationErrorCode::SignatureInvalid, 'Signature verification failed and the well-known document could not be refetched.');
            }

            if ($previousUuid !== null && $partner->node_uuid !== $previousUuid) {
                return $this->reject($request, $senderDomain, FederationErrorCode::SignatureInvalid, 'The node UUID for this domain changed; the partnership requires re-approval.');
            }

            $result = $this->verify($request, $keyId, $timestamp, $signature, $partner->publishedKeys());

            if (! $result->verified) {
                return $this->reject($request, $senderDomain, $result->error ?? FederationErrorCode::SignatureInvalid, 'Signature verification failed after key refresh.');
            }
        }

        // Authenticated but unapproved partners receive no listings until
        // a human approves them (FP-13). Endpoints that exist for
        // unapproved partners — the partnership request itself — opt out
        // with the allow-provisional parameter.
        if ($mode !== 'allow-provisional' && $partner->trust_level !== TrustLevel::Verified) {
            return $this->reject($request, $senderDomain, FederationErrorCode::PartnerProvisional, 'Partnership is pending approval; no listings are shared yet.');
        }

        $request->attributes->set('openyacht_partner', $partner);

        $response = $next($request);

        Log::info('openyacht.request', [
            'partner' => $senderDomain,
            'path' => $request->getRequestUri(),
            'outcome' => 'ok',
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * @param  array<string, string>  $publishedKeys
     */
    private function verify(Request $request, string $keyId, string $timestamp, string $signature, array $publishedKeys): VerificationResult
    {
        return $this->verifier->verify(
            method: $request->getMethod(),
            pathWithQuery: $request->getRequestUri(),
            receivingHost: $request->getHost(),
            rawBody: (string) $request->getContent(),
            senderKeyId: $keyId,
            timestamp: $timestamp,
            signature: $signature,
            publishedKeys: $publishedKeys,
        );
    }

    private function reject(Request $request, string $senderDomain, FederationErrorCode $code, string $message): Response
    {
        Log::info('openyacht.request', [
            'partner' => $senderDomain,
            'path' => $request->getRequestUri(),
            'outcome' => strtolower($code->value),
            'status' => $code->httpStatus(),
        ]);

        return FederationErrorResponse::make($code, $message);
    }
}
