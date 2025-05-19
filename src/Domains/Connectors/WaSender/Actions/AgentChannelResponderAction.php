<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\AgentMonitoring;

class AgentChannelResponderAction
{
    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent
    ) {
    }

    public function execute(array $params = []): array
    {
        //$messageConversation = $this->message->message['raw_data']['message']['conversation'] ?? null;
        $messageConversation = $this->message->message['raw_data']['message']['conversation'] ??
                       $this->message->message['raw_data']['message']['extendedTextMessage']['text'] ?? null;
        $channelId = Str::replace('@s.whatsapp.net', '', $this->message->message['chat_jid']);

        $isImageText = MessageTypeEnum::isDocumentType($this->message->messageType->verb);

        if ($isImageText) {
            $downloadMessageFileAction = new DownloadMessageFileAction(
                $this->channel,
                $this->message,
                $this->agent,
            )->execute();

            $previousMessage = $this->channel->getPreviousMessage($this->message);

            if ($previousMessage && MessageTypeEnum::isDocumentType($previousMessage->messageType->verb) && $previousMessage->id !== $this->message->id) {
                //$this->message->associate($previousMessage);
                $previousMessage = $previousMessage->parent ?? $previousMessage;
                $this->message->parent_id = $previousMessage->id;
                $this->message->disableWorkflows();
                $this->message->save();
                $this->message->enableWorkflows();
                $this->message->fireWorkflow(WorkflowEnum::DURING_WORKFLOW->value, true, [
                    'app' => $this->message->app,
                    'company' => $this->message->company,
                ]);
            }

            $messageConversation = 'Keep record we just processed files under the parent message 
                    .' . ($previousMessage ? $previousMessage->id : $this->message->id) . ' so we can reference it to process 
                    later and return the msg id so the I know about it';
        }

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $useInspector = $this->message->app->get('inspector-key') !== null;

        $currentAgent = new $this->agent->type->handler();
        //$currentAgent = $this->agent;

        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity()
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($this->message->app->get('inspector-key'))
            );
            $currentAgent->observe(
                new AgentMonitoring($inspector)
            );
        }

        $question = $currentAgent->chat(new UserMessage($messageConversation));
        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        $whatsAppMessageService = new MessageService(
            $this->message->app,
            $this->message->company
        );

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $whatsAppMessageService->sendTextMessage(
                $channelId,
                $responseText,
            ),
        ];
    }
}
