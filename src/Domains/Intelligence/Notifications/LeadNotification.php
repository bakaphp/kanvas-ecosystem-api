<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Users\Models\Users;

class LeadNotification extends \Illuminate\Notifications\Notification
{
    public array $channels = [];

    public function __construct(
        protected Lead $lead,
        protected string $message,
        protected array $enabledChannels,
        protected Apps $app,
        protected Companies $company,
        protected ?Users $fromUser = null
    ) {
        $this->channels = $this->mapChannels($enabledChannels);
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $toEmail = $notifiable instanceof UserInterface ? $notifiable->email : $notifiable->routes['mail'];

        return (new MailMessage())
            ->subject('Lead Notification - ' . $this->lead->people->name)
            ->line($this->message)
            ->line('Lead: ' . $this->lead->people->name);
    }

    public function toSms(object $notifiable): array
    {
        $phone = null;
        if ($notifiable instanceof UserInterface) {
            $appProfile = $notifiable->getAppProfile($this->app);
            $phone = $appProfile?->phone ?? $notifiable->phone;
        }

        return [
            'user_id' => $notifiable instanceof UserInterface ? $notifiable->getId() : null,
            'phone' => $phone,
            'content' => $this->message,
            'title' => 'Lead Notification',
            'app' => $this->app,
            'company' => $this->company,
        ];
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'user_id' => $notifiable instanceof UserInterface ? $notifiable->getId() : null,
            'message' => $this->message,
            'title' => 'Lead Notification',
            'subtitle' => $this->lead->people->name,
            'apps_id' => $this->app->getId(),
            'data' => [
                'lead_id' => $this->lead->getId(),
            ],
        ];
    }

    private function mapChannels(array $enabledChannels): array
    {
        $channelMap = [
            NotificationChannelEnum::SMS->value => TwilioSmsChannel::class,
            NotificationChannelEnum::EMAIL->value => 'mail',
            NotificationChannelEnum::PUSH->value => OneSignalNotificationChannel::class,
        ];

        $channels = [];
        foreach ($enabledChannels as $channel) {
            if (! NotificationChannelEnum::isSupported($channel)) {
                continue;
            }

            if (isset($channelMap[$channel])) {
                $channels[] = $channelMap[$channel];
            }
        }

        return $channels;
    }
}
