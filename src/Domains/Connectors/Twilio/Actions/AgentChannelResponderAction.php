<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'twilio-sms';
    protected string $communicationChannel = 'sms';

    #[Override]
    public function execute(array $params = []): array
    {
        $entity = $this->message->entity();
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        $batchKey = $params['batchKey'] ?? null;
        $batch = null;
        if ($batchKey !== null && Cache::has($batchKey)) {
            $batch = Cache::get($batchKey);

            if (isset($batch['last_message_id']) && $batch['last_message_id'] !== $this->message->getId()) {
                return [
                    'message' => 'this is not the last message in the batch, skipping , we only respond to the last message',
                    'batch' => $batch,
                ];
            }

            Cache::forget($batchKey);
        }

        $messageConversation = $this->message->message['content'];
        if ($batchKey !== null && $batch !== null) {
            $messageConversation = '';
            foreach ($batch['messages'] as $batchMessage) {
                $messageConversation .= $batchMessage['body'] . "\n";
            }
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

        $to = Str::toE164(Str::replace('twilio-', '', $this->channel->slug));
        $to = $this->hijackMessagePhone($to);

        $messageResponse = $this->createMessage(
            $responseText,
            $to,
            $this->message,
            $this->channel,
            $params['from']
        );

        if (! $messageResponse->is_locked) {
            Client::getInstanceByCompany($this->message->company)
                ->messages->create(
                    $to,
                    [
                        'from' => $params['from'],
                        'body' => $responseText,
                    ]
                );
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
