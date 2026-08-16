<?php

declare(strict_types=1);

namespace Kanvas\Auth\Notifications;

use Baka\Contracts\AppInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignupAnomalyNotification extends Notification
{
    public function __construct(
        protected AppInterface $app,
        protected int $signupsLastHour,
        protected float $hourlyBaseline,
        protected int $blockedLastHour,
        protected int $multiplier,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $baseline = number_format($this->hourlyBaseline, 1);
        $factor = $this->hourlyBaseline > 0.0
            ? number_format((float) $this->signupsLastHour / $this->hourlyBaseline, 1) . '×'
            : 'no prior baseline';

        return new MailMessage()
            ->subject('Signup spike on ' . $this->app->name . ': ' . $this->signupsLastHour . ' in the last hour')
            ->line('An app is registering users far faster than it normally does.')
            ->line('App: ' . $this->app->name . ' (ID: ' . $this->app->getId() . ')')
            ->line('Signups in the last hour: ' . $this->signupsLastHour)
            ->line('Normal rate: ' . $baseline . '/hour — this hour is ' . $factor . ' that.')
            ->line('Blocked as spam in the same hour: ' . $this->blockedLastHour)
            ->line('Alert threshold: ' . $this->multiplier . '× the baseline.')
            ->line(
                'A high blocked count means the filters are holding. A high signup count with a '
                . 'low blocked count means the campaign is getting through — check the '
                . 'registration-spam issues in Sentry for the addresses being used.'
            );
    }
}
