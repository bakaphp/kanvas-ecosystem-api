<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\BaseAgentResponderAction;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

class AIAssistAgentResponderAction extends BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'ai-assist';
    protected string $communicationChannel = 'ai-assist';

    #[Override]
    public function execute(array $params = []): array
    {
        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $currentAgent = new $this->agent->type->handler();
        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity(),
            null,
            $this->message->company->getAiAgentUserOrFail(),
        );

        $aiAssistAppName = $this->channel->company->get(ConfigurationEnum::ADK_AI_ASSIST_APP_NAME->value)
            ?? $this->channel->app->get(ConfigurationEnum::ADK_AI_ASSIST_APP_NAME->value)
            ?? null;

        $aiAssistBaseUrl = $this->channel->company->get(ConfigurationEnum::ADK_AI_ASSIST_BASE_URL->value)
            ?? $this->channel->app->get(ConfigurationEnum::ADK_AI_ASSIST_BASE_URL->value)
            ?? null;

        $onChunk = function ($text, $data): void {
            $this->createMessage(
                Str::markdown($text),
                'ai-assist',
                $this->message,
                $this->channel
            );
        };

        $messageConversation = $this->message->message['content'];

        if ($currentAgent instanceof ADKAgent) {
            $adkService = new GoogleADKService(
                $this->channel->app,
                $this->channel->company,
                $aiAssistAppName,
                $aiAssistBaseUrl
            );

            $sessionId = $this->session ? $this->session->uuid : $this->channel->slug;
            $userId = (string) $this->channel->entity_id;

            $adkService->startSession($userId, $sessionId);

            $responseContent = $adkService->chat(
                $userId,
                $sessionId,
                $messageConversation,
                $onChunk,
                user: $this->message->user
            );
        } else {
            $question = $currentAgent->chat(new UserMessage($messageConversation));
            $responseContent = $question->getContent();
            $responseText = ChatHelper::extractTextFromResponse($responseContent);
            $onChunk($responseText, []);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
        ];
    }
}
