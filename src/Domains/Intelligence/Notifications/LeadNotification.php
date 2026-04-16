<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
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

        $this->channels = $enabledChannels;
    }

    public function getNotificationTitle(): string
    {
        return 'Reminder Notification - ' . $this->lead->people->name;
    }

    public function getEmailContent(): string
    {
        return $this->message;
    }

    protected function getSmsTemplate(): string
    {
        return $this->message;
    }

    protected function getPushTemplate(): string
    {
        return json_encode([
            'message' => $this->message,
            'title' => 'Reminder Notification',
            'subtitle' => $this->lead->people->name,
            'apps_id' => $this->lead->entity->getId(),
            'data' => [
                'lead_id' => $this->lead->getId(),
            ],
        ]);
    }
}
