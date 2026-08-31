<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Notifications;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail + push notification to a plan's human owner about a change to their plan — the agent moved
 * work on the board (a status change synced from the runtime kanban) or the plan was just assigned to
 * them. Template-free — content comes from the passed title/message/metadata; the actor (fromUser) is
 * the agent's user, falling back to the plan's creator when the plan has no owning agent.
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

    /**
     * Slack identifies the sender by the bot token, which lives on an AGENT rather than the app — so an
     * agent is what speaks here, and one that was never connected to Slack sends nothing, leaving the
     * channel quiet instead of failing the notification.
     *
     * The agent that ASKED for the work comes first: it is the one the person has been talking to, and
     * the worker is usually a hired agent with no Slack connection at all.
     *
     * @return array<string, mixed>
     */
    public function toSlack(UserInterface|AnonymousNotifiable $notifiable): array
    {
        $title = trim((string) ($this->data['title'] ?? ''));
        $message = trim((string) ($this->data['message'] ?? ''));

        $plan = $this->entity instanceof Plan ? $this->entity : null;

        return [
            'agent' => $plan?->createdByAgent ?? $plan?->agent,
            'text' => $title !== '' ? '*' . $title . '*\n' . $message : $message,
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
