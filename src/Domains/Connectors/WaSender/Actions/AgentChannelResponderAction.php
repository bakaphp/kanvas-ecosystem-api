<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'whatsapp';
    protected string $communicationChannel = 'whatsapp';

    #[Override]
    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['raw_data']['message']['conversation']
            ?? $this->message->message['raw_data']['message']['extendedTextMessage']['text']
            ?? null;

        $channelId = Str::replace('@s.whatsapp.net', '', $this->hijackMessagePhone($this->message->message['chat_jid']));

        $isImageText = (bool) ($params['process_document'] ?? false);

        if ($isImageText && $params['lastMessageParentDocument'] instanceof Message) {
            $lastMessage = $params['lastMessageParentDocument'];

            $lastMessage->fireWorkflow(WorkflowEnum::DURING_WORKFLOW->value, true, [
                'app' => $this->message->app,
                'company' => $this->message->company,
            ]);

            $messageConversation = 'Keep record we just processed files under the parent message '
                . $lastMessage->id . ' so we can reference it to process '
                . 'later and return the msg id so the I know about it';
        }

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        $entity = $this->message->entity();
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

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
            $channelId,
            $this->message,
            $this->channel,
            rawResponse: $responseContent
        );

        if (! $messageResponse->is_locked) {
            new MessageService(
                $this->message->app,
                $this->message->company
            )->sendTextMessage($channelId, $responseText);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
