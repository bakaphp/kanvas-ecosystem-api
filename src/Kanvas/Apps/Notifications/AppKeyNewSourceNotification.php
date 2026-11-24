<?php

declare(strict_types=1);

namespace Kanvas\Apps\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A platform alert with no tenant entity or template behind it, so it is a plain mail message like
 * SignupAnomalyNotification. Queued because it is raised from inside the request it reports on.
 */
class AppKeyNewSourceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $keyName,
        protected string $clientId,
        protected int $appId,
        protected string $appName,
        protected string $ip,
        protected ?string $userAgent,
        protected string $seenAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(sprintf('App key "%s" used from a new IP: %s (%s)', $this->keyName, $this->ip, $this->appName))
            ->line('An app key was used from an IP address it has never been used from before.')
            ->line('App: ' . $this->appName . ' (ID: ' . $this->appId . ')')
            ->line('Key: ' . $this->keyName . ' (client id ' . $this->clientId . ')')
            ->line('IP: ' . $this->ip)
            ->line('User agent: ' . ($this->userAgent ?? 'none'))
            ->line('When: ' . $this->seenAt . ' UTC')
            ->line(
                'If this is not a server you deployed, rotate the key: '
                . 'php artisan kanvas:app-key-revoke ' . $this->appId . ' --key=' . $this->clientId . ' --rotate'
            );
    }
}
