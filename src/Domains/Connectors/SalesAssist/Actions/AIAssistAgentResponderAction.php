<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Override;

class AIAssistAgentResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'ai-assist';
    protected string $communicationChannel = 'ai-assist';
    protected bool $respectsLeadAiMode = false;

    #[Override]
    public function execute(array $params = []): array
    {
        $entity = $this->message->entity();

        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        $messageConversation = $this->message->message['content'];

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageConversation,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
            adkAppName: $this->aiAssistConfig(ConfigurationEnum::ADK_AI_ASSIST_APP_NAME),
            adkBaseUrl: $this->aiAssistConfig(ConfigurationEnum::ADK_AI_ASSIST_BASE_URL),
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        $this->createMessage(
            Str::markdown($responseText),
            'ai-assist',
            $this->message,
            $this->channel
        );

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
        ];
    }

    /**
     * AI Assist talks to its own ADK app (separate from the customer-facing orchestrator),
     * so the target is read off the channel's company/app config, not the ADK defaults.
     */
    private function aiAssistConfig(ConfigurationEnum $key): ?string
    {
        $value = $this->channel->company->get($key->value)
            ?? $this->channel->app->get($key->value);

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
