<?php

namespace App\Notifications;

use App\Models\FederationPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An unknown node introduced itself and was recorded as a provisional
 * partner (trust on first use). A provisional partner nobody notices
 * means nobody federating — someone has to approve or block it (FP-13).
 *
 * Queued, and the partner is re-fetched when the queue drains, so a
 * request message stored by the partners/request endpoint after the
 * middleware fired this is in the mail. With a sync queue the mail goes
 * out before the endpoint runs and the message is simply absent.
 *
 * // federation-protocol.md §Identity and Trust Model
 */
class PartnerFirstContact extends Notification implements ShouldQueue
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
            ->subject(__('federation.notifications.first_contact.subject', ['domain' => $this->partner->domain]))
            ->line(__('federation.notifications.first_contact.intro', ['domain' => $this->partner->domain]));

        if ($this->partner->request_message !== null) {
            $mail->line(__('federation.notifications.first_contact.message', ['message' => $this->partner->request_message]));
        }

        if ($this->partner->request_contact_email !== null) {
            $mail->line(__('federation.notifications.first_contact.contact', ['email' => $this->partner->request_contact_email]));
        }

        return $mail
            ->line(__('federation.notifications.first_contact.explanation'))
            ->action(__('federation.notifications.review_partner'), route('partners.show', $this->partner));
    }
}
