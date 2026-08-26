<?php

namespace App\Notifications;

use App\Models\FederationPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The node UUID served by a partner's domain changed: the domain now
 * hosts a different installation — a reinstall, a migration, or a
 * domain takeover. The partner was downgraded to provisional; a human
 * must verify out of band before re-approving (FP-11).
 *
 * // federation-protocol.md §Request Signing — Verification procedure
 */
class PartnerNodeUuidChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FederationPartner $partner) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('federation.notifications.uuid_changed.subject', ['domain' => $this->partner->domain]))
            ->line(__('federation.notifications.uuid_changed.intro', ['domain' => $this->partner->domain]))
            ->line(__('federation.notifications.uuid_changed.explanation'))
            ->action(__('federation.notifications.review_partner'), route('partners.show', $this->partner));
    }
}
