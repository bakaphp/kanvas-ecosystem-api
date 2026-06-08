<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Post a message onto a Plan's Activities channel as the agent's user (or an explicit author). Shared
 * by the chat-wake reply and the kanban ingest (so a board-driven agent's completion summary still
 * shows up in the channel, not just in plan.output). Best-effort: failures are reported, not fatal.
 */
class PostPlanActivityMessageAction
{
    public function __construct(
        private readonly Plan $plan,
        private readonly string $content,
        private readonly ?Users $author = null,
    ) {
    }

    public function execute(): ?Message
    {
        try {
            $channel = $this->plan->socialChannels->first();
            $author = $this->author ?? $this->plan->agent?->user ?? $this->plan->user;

            if ($channel === null || $author === null || trim($this->content) === '') {
                return null;
            }

            $messageType = new CreateMessageTypeAction(
                new MessageTypeInput(
                    apps_id: $this->plan->app->getId(),
                    languages_id: 1,
                    name: 'agent_reply',
                    verb: 'agent_reply',
                    template: '{{message}}',
                    templates_plura: '{{message}}',
                ),
            )->execute();

            $message = new CreateMessageAction(
                new MessageInput(
                    app: $this->plan->app,
                    company: $this->plan->company,
                    user: $author,
                    type: $messageType,
                    message: [
                        'content' => $this->content,
                        'from_me' => true,
                    ],
                ),
            )->execute();

            $channel->addMessage($message, $author);

            return $message;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
