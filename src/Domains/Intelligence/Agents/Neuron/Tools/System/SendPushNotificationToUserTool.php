<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Notifications\AgentPushNotification;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Push Notification', category: 'ecosystem')]
class SendPushNotificationToUserTool extends Tool
{
    public function __construct(private readonly ?Agent $agent = null)
    {
        parent::__construct(
            name: 'send_push_notification_to_user',
            description: 'Send a push notification to the registered devices of a Kanvas user in this company, '
                . 'identified by their email address. Use it for short, timely nudges — a task finished, an order '
                . 'is ready, something needs their attention now. Keep the message under ~150 characters; this is '
                . 'not for long content (use send_email_to_user for that). The recipient must be a member of this '
                . 'company; you cannot notify arbitrary outside people, and delivery goes only to the devices the '
                . 'user has registered — you cannot choose the destination.',
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
                name: 'recipient_email',
                type: PropertyType::STRING,
                description: "The recipient's email address. Must belong to a user in this company.",
                required: true,
            ),
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
    public function __invoke(string $recipient_email, string $title, string $message): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot send a push notification.'];
        }

        $recipient_email = trim($recipient_email);
        $title = trim($title);
        $message = trim($message);

        if ($recipient_email === '' || $title === '' || $message === '') {
            return ['status' => 'error', 'message' => 'A recipient email, a title, and a message are all required.'];
        }

        try {
            $recipient = UsersRepository::getUserOfAppByEmail($recipient_email, $this->agent->app);
            UsersRepository::belongsToCompany($recipient, $this->agent->company);
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No user in this company has the email {$recipient_email}. Ask the user to confirm who to notify.",
            ];
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
