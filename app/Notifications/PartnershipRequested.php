<?php

namespace App\Notifications;

use App\Models\FederationPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A partner this node already knows sent a partnership request — one
 * that contacted us earlier, or one an operator added here that is now
 * introducing itself back. First contact rides the first-contact mail;
 * this one exists so a request from a known partner is not invisible,
 * since it is the message and contact the approving human decides on
 * (FP-13).
 *
 * // federation-protocol.md §Partner Lifecycle
 */
class PartnershipRequested extends Notification implements ShouldQueue
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
        $mail = (new MailMessage)
            ->subject(__('federation.notifications.partnership_requested.subject', ['domain' => $this->partner->domain]))
            ->line(__('federation.notifications.partnership_requested.intro', [
                'domain' => $this->partner->domain,
                'trust_level' => strtolower($this->partner->trust_level->label()),
            ]));

        if ($this->partner->request_message !== null) {
            $mail->line(__('federation.notifications.request_message', ['message' => $this->partner->request_message]));
        }

        if ($this->partner->request_contact_email !== null) {
            $mail->line(__('federation.notifications.request_contact', ['email' => $this->partner->request_contact_email]));
        }

        return $mail
            ->line(__('federation.notifications.partnership_requested.explanation'))
            ->action(__('federation.notifications.review_partner'), route('partners.show', $this->partner));
    }
}
