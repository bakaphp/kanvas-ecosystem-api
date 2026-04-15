<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;

class LeadNotification extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected string $message,
        protected array $enabledChannels,
        ?Users $fromUser = null
    ) {
        parent::__construct($lead, [
            'company' => $lead->company,
            'fromUser' => $fromUser,
        ]);

        $this->channels = $this->filterChannels($enabledChannels);
    }

    public function getNotificationTitle(): string
    {
        return 'Lead Notification - ' . $this->lead->people->name;
    }

    public function getEmailContent(): string
    {
        return $this->message;
    }

    public function toOneSignal($notifiable): array
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

    private function filterChannels(array $enabledChannels): array
    {
        return array_values(
            array_filter(
                $enabledChannels,
                fn ($channel) => NotificationChannelEnum::isSupported($channel)
            )
        );
    }
}
