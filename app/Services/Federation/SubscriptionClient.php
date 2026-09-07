<?php

namespace App\Services\Federation;

use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The consumer half of push subscriptions (API-11), wire side: register
 * this node's inbox as a partner's callback, or remove it. What arrives
 * at the inbox is handled by InboxController and SyncService.
 *
 * A subscription is an optional feature, so the partner's capabilities
 * document is checked before the request (API-7); a partner without it
 * is simply polled. It also never replaces polling: a subscribed
 * partner is still reconciled by a daily updated_since poll
 * (SyncService::isDue), because missed webhooks are a fact of life.
 *
 * // api-design.md §Subscriptions
 */
class SubscriptionClient
{
    public function __construct(private SignedClient $client, private OutboundUrlGuard $guard) {}

    /**
     * The inbox partners deliver to: fixed on the identity domain, like
     * every other federation path.
     */
    public function inboxUrl(): string
    {
        return 'https://'.config('openyacht.domain').'/openyacht/v1/inbox';
    }

    /**
     * @throws SubscriptionFailed with a translated reason
     */
    public function subscribe(FederationPartner $partner): void
    {
        // The inbox accepts pushes from verified partners only — the
        // same bar as the feed — so subscribing to anyone else would
        // only produce deliveries this node then rejects.
        if ($partner->trust_level !== TrustLevel::Verified) {
            throw new SubscriptionFailed(__('federation.subscription.partner_not_verified', ['domain' => $partner->domain]));
        }

        if (! $this->advertisesSubscriptions($partner)) {
            throw new SubscriptionFailed(__('federation.subscription.unsupported', ['domain' => $partner->domain]));
        }

        $response = $this->attempt(
            $partner,
            fn (): Response => $this->client->post($partner, '/openyacht/v1/subscriptions', ['callback' => $this->inboxUrl()]),
        );

        if (! $response->successful()) {
            throw new SubscriptionFailed($this->unexpectedAnswer($partner, $response));
        }

        $partner->update(['push_subscribed_at' => now()]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain, 'callback' => $this->inboxUrl()])
            ->event('push_subscribed')
            ->log("Subscribed to pushes from {$partner->domain}");
    }

    /**
     * A partner that no longer serves the endpoint (404/405/410) has
     * nothing left to remove, so the local record is cleared either way.
     *
     * @throws SubscriptionFailed with a translated reason
     */
    public function unsubscribe(FederationPartner $partner): void
    {
        $response = $this->attempt(
            $partner,
            fn (): Response => $this->client->delete($partner, '/openyacht/v1/subscriptions'),
        );

        if (! $response->successful() && ! in_array($response->status(), [404, 405, 410], true)) {
            throw new SubscriptionFailed($this->unexpectedAnswer($partner, $response));
        }

        $partner->update(['push_subscribed_at' => null]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain])
            ->event('push_unsubscribed')
            ->log("Unsubscribed from pushes from {$partner->domain}");
    }

    /**
     * Capability check before using the optional feature (API-7). The
     * document is unsigned and public; a partner that cannot be read
     * counts as not advertising it.
     */
    private function advertisesSubscriptions(FederationPartner $partner): bool
    {
        try {
            $this->guard->assertPublicHost($partner->domain);

            $response = Http::timeout(15)
                ->withoutRedirecting()
                ->get("https://{$partner->domain}/openyacht/v1/capabilities");
        } catch (ConnectionException|BlockedOutboundHost) {
            return false;
        }

        return $response->successful()
            && data_get($response->json(), 'features.subscriptions') === true;
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws SubscriptionFailed
     */
    private function attempt(FederationPartner $partner, callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new SubscriptionFailed(__('federation.subscription.unreachable', [
                'domain' => $partner->domain,
                'reason' => $exception->getMessage(),
            ]), previous: $exception);
        } catch (RuntimeException $exception) {
            // BlockedOutboundHost (the outbound guard refused the host) or
            // the Signer's "no active key" — both for the operator to fix.
            throw new SubscriptionFailed($exception->getMessage(), previous: $exception);
        }
    }

    private function unexpectedAnswer(FederationPartner $partner, Response $response): string
    {
        $code = data_get($response->json(), 'error.code');

        return __('federation.subscription.unexpected_answer', [
            'domain' => $partner->domain,
            'status' => $response->status(),
            'code' => is_string($code) ? $code : __('federation.introduction.no_error_code'),
        ]);
    }
}
