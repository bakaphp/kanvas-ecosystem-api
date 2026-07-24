<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Shared reply-persistence for the channel responders (InternalAgentChannelResponderAction,
 * RuntimeAgentChannelResponderAction). Subclasses own agent-resolution / execute() and must
 * expose $this->agent, $this->message, $this->channel via their own constructor.
 */
abstract class AbstractAgentChannelResponderAction
{
    protected const string AGENT_RESPONSE_TYPE_VERB = 'ai-agent-response';

    abstract public function execute(): Message;

    /**
     * Extra keys merged into the outbound message payload. Overridden by
     * InternalAgentChannelResponderAction to attach `session_id`.
     */
    protected function extraMessagePayload(): array
    {
        return [];
    }

    /**
     * Push-notify the author of the inbound message that the agent answered them.
     * Mirrors the userChat path (PersistChatTurnToSocialAction) so every agent reply
     * a human can see triggers the same notification, regardless of runtime.
     */
    protected function notifyRecipientOfReply(Message $replyMessage): void
    {
        $authorId = $this->message->users_id;

        if ($authorId <= 0) {
            return;
        }

        try {
            $recipient = Users::getById($authorId);
        } catch (ModelNotFoundException) {
            return;
        }

        $recipient->notify(
            new AgentReplyNotification(
                $replyMessage,
                $this->agent,
                $this->agent->user
            )
        );
    }

    protected function createReplyMessage(string $reply): Message
    {
        $app = $this->message->app;
        $originalPayload = $this->message->getMessage();
        $entity = $this->message->entity();

        // attachToChannel: false — CREATED must fire after the entity link is attached (so rules
        // see the entity) but before the channel attach, which stays the true last step.
        $replyMessage = new PostChannelMessageAction(
            channel: $this->channel,
            author: $this->agent->user,
            verb: self::AGENT_RESPONSE_TYPE_VERB,
            content: $reply,
            extraPayload: AiChatMessagePayload::from(array_merge(
                [
                    'content' => $reply,
                    'from_me' => true,
                    'from_ia' => true,
                ],
                $this->extraMessagePayload(),
                [
                    'agent_id' => (int) $this->agent->getId(),
                    'raw_data' => $reply,
                    'message_id' => '--',
                    'chat_jid' => $originalPayload['chat_jid'] ?? null,
                ],
            ))->toArray(),
            runWorkflow: false,
            entity: $entity instanceof Model ? $entity : null,
            tags: [self::AGENT_RESPONSE_TYPE_VERB],
            attachToChannel: false,
            messageTypeName: self::AGENT_RESPONSE_TYPE_VERB,
        )->execute();

        $replyMessage->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            ['app' => $app],
        );

        $this->channel->addMessage($replyMessage, $this->agent->user);

        return $replyMessage;
    }
}
