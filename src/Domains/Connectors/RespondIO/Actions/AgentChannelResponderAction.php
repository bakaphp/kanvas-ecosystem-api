<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'respondio-text';
    protected string $communicationChannel = 'respondio';

    #[Override]
    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['content'] ?? null;

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        $entity = $this->message->entity();
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        $phone = $this->hijackMessagePhone($this->message->message['chat_jid']);

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageConversation,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        $messageResponse = $this->createMessage(
            $responseText,
            $phone,
            $this->message,
            $this->channel
        );

        if (! $messageResponse->is_locked) {
            new Client(
                $this->message->app,
                $this->message->company
            )->sendMessage($phone, $responseText);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
