<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Notifications\Notification;
use Override;

class ScheduledReminderNotification extends Notification
{
    /**
     * @param list<string> $via
     */
    public function __construct(
        ScheduledAction $action,
        string $message,
        array $via = ['mail', 'push'],
    ) {
        $fromUser = $action->agent?->user ?? $action->recipient;

        $data = [
            'app' => $action->app,
            'company' => $action->company,
            'title' => 'Reminder',
            'message' => $message,
            'fromUser' => $fromUser,
        ];

        parent::__construct($action, $data);
        $this->setType('blank');
        $this->setSubject('Reminder');
        $this->setData($data);

        if ($fromUser !== null) {
            $this->setFromUser($fromUser);
        }

        $this->channels = $via;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toOneSignal(UserInterface|AnonymousNotifiable $notifiable): array
    {
        if (! $notifiable instanceof UserInterface) {
            return [];
        }

        return [
            'user_id' => $notifiable->getId(),
            'title' => (string) ($this->data['title'] ?? 'Reminder'),
            'message' => (string) ($this->data['message'] ?? ''),
            'subtitle' => '',
            'apps_id' => $this->app->getId(),
            'data' => $this->getData(),
        ];
    }

    #[Override]
    public function getEmailContent(): string
    {
        return '<p>' . e((string) ($this->data['message'] ?? '')) . '</p>';
    }
}
