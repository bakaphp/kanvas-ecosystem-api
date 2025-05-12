<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
use Kanvas\Intelligence\Agents\Types\CRMAgent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;

class AgentChannelResponderAction
{
    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected BaseAgent $agent
    ) {
    }

    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['raw_data']['message']['conversation'] ?? null;
        $channelId = Str::replace('@s.whatsapp.net', '', $this->message->message['chat_jid']);

        if ($this->message->entity === null) {
            throw new ValidationException('No entity found');
        }

        $crmAgent = CRMAgent::make();
        $crmAgent->setConfiguration($this->agent, $this->message->entity);

        $question = $crmAgent->chat(new UserMessage($messageConversation));
        $response = $question->getContent();

        $whatsAppMessageService = new MessageService(
            $this->message->app,
            $this->message->company
        );

        return $whatsAppMessageService->sendTextMessage(
            $channelId,
            $response
        );
    }
}
