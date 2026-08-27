<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An administrator created an account for this person. Self-registration
 * is disabled, so this mail is their only route in: it carries a
 * password-reset token they exchange for a password of their own, which
 * means the administrator never handles their credentials.
 *
 * The token is issued by the standard password broker and expires with
 * it (config/auth.php passwords.users.expire), so the mail also points
 * at the forgot-password page — an expired invitation is self-service to
 * replace rather than something an administrator has to reissue.
 */
class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $invitedByName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject(__('users.invitation.subject', ['app' => config('app.name')]))
            ->greeting(__('users.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('users.invitation.intro', [
                'inviter' => $this->invitedByName,
                'app' => config('app.name'),
            ]))
            ->action(__('users.invitation.action'), $url)
            ->line(__('users.invitation.expiry', [
                'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]))
            ->line(__('users.invitation.expired_fallback', ['url' => route('password.request')]));
    }
}
