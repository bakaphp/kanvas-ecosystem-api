<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail + push notification to a plan's human owner when the agent moves work on the board
 * (a status change synced back from the runtime kanban). Template-free — content comes from the
 * passed title/message/metadata; the actor (fromUser) is the agent's user.
 */
class PlanProgressNotification extends Notification
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $via
     */
    public function __construct(
        Plan $plan,
        string $title,
        string $message,
        array $metadata = [],
        array $via = ['mail', 'push'],
    ) {
        $fromUser = $plan->agent?->user ?? $plan->user;

        $data = [
            'app' => $plan->app,
            'company' => $plan->company,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
            'fromUser' => $fromUser,
        ];

        parent::__construct($plan, $data);
        $this->setType('blank');
        $this->setSubject($title);
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
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'subtitle' => '',
            'apps_id' => $this->app->getId(),
            'data' => $this->getData(),
        ];
    }

    #[Override]
    public function getEmailContent(): string
    {
        $title = (string) ($this->data['title'] ?? 'Plan update');
        $message = (string) ($this->data['message'] ?? '');

        return '<h2>' . e($title) . '</h2><p>' . e($message) . '</p>';
    }
}
