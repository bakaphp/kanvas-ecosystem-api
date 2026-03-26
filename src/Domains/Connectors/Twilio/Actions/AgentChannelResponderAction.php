<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentResponderAction;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;
use Override;

class AgentChannelResponderAction extends BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'twilio-sms';
    protected string $communicationChannel = 'sms';

    #[Override]
    public function execute(array $params = []): array
    {
        if ($this->message->entity() === null) {
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

        $useInspector = $this->message->app->get('inspector-key') !== null;

        $currentAgent = new $this->agent->type->handler();
        //$currentAgent = $this->agent;

        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity()->people
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($this->message->app->get('inspector-key'))
            );
            $currentAgent->observe(
                new InspectorObserver($inspector)
            );
        }

        $to = Str::replace('twilio-', '', $this->channel->slug);
        $to = Str::replace('+', '', $to); // Strip any existing +

        // Add country code if needed (assuming +1 for 10-digit numbers)
        if (strlen($to) === 10) {
            $to = "+1{$to}";
        } else {
            $to = "+{$to}";
        }
        //if its missing a +1 add it
        $to = $this->hijackMessagePhone($to);

        /** @var Lead $lead */
        $lead = $this->message->entity();
        $onChunk = function ($text, $data) use ($to, $params, $lead): void {
            $response = $this->createMessage($text, $to, $this->message, $this->channel, $params['from']);

            if (! $response->is_locked) {
                new SendMessageToLeadAction($lead)->execute(
                    $this->communicationChannel,
                    $text,
                    $params['from']
                );
            }
        };

        $messageConversation = $this->message->message['content'];
        if ($batchKey !== null && $batch !== null) {
            $messageConversation = '';
            foreach ($batch['messages'] as $batchMessage) {
                $messageConversation .= $batchMessage['body'] . "\n";
            }
        }

        $question = $currentAgent instanceof ADKAgent ?
                $currentAgent->chat(
                    $this->channel,
                    $this->message,
                    $messageConversation,
                    $onChunk,
                    $this->session
                ) : $currentAgent->chat(new UserMessage($messageConversation));

        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        //if its not an ADKAgent, send the response as a text message
        if (! ($currentAgent instanceof ADKAgent)) {
            $onChunk($responseText, []);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
