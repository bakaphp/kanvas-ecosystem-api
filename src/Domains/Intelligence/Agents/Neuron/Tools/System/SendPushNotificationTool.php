<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesConversationHuman;
use Kanvas\Intelligence\Notifications\AgentPushNotification;
use Kanvas\Intelligence\Sessions\Models\Session;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Push Notification', category: 'ecosystem')]
class SendPushNotificationTool extends Tool
{
    use HasKanvasContext;
    use ResolvesConversationHuman;

    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?Session $session = null,
    ) {
        parent::__construct(
            name: 'send_push_notification',
            description: 'Send a push notification to the registered devices of the current user — the person '
                . 'you are talking to. Use it for short, timely nudges: a reminder fired, a task finished, '
                . 'something needs their attention now. Keep the message under ~150 characters; this is not for '
                . 'long content (use send_email_to_user for that). Delivery is push only — no email — and it '
                . 'goes ONLY to the current user\'s own devices; you cannot choose a different recipient.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'The push notification title. Short and specific — a few words.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The push notification body. Plain text, ideally under 150 characters — no markdown.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $title, string $message): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot send a push notification.'];
        }

        $title = trim($title);
        $message = trim($message);

        if ($title === '' || $message === '') {
            return ['status' => 'error', 'message' => 'A title and a message are both required.'];
        }

        // The human in the conversation, not $this->user — on an in-app agent surface $this->user is the
        // agent's OWN user, so falling back to it means the agent notifies itself (the self-reminder case).
        $recipient = $this->conversationHuman($this->session) ?? $this->contextUser();

        if ($recipient === null) {
            return ['status' => 'error', 'message' => 'There is no user in scope to notify, so I cannot send a push notification.'];
        }

        try {
            $recipient->notify(new AgentPushNotification($this->agent, $title, $message));
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'The push notification could not be sent right now. Tell the user you will follow up.'];
        }

        return [
            'status' => 'success',
            'to' => $recipient->email,
            'title' => $title,
            'message' => "Push notification sent to {$recipient->email}'s devices.",
        ];
    }
}
