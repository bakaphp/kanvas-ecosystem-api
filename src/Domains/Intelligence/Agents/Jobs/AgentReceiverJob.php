<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\ProcessAgentChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class AgentReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $configuration = $this->receiver->configuration ?? [];
        $agentId = (int) ($configuration['agent_id'] ?? 0);

        if ($agentId === 0) {
            throw new ValidationException('Receiver configuration is missing required key: agent_id');
        }

        $agent = Agent::getByIdWithGlobalFallback(
            $agentId,
            $this->receiver->app,
            $this->receiver->company,
        );

        $payload = $this->webhookRequest->payload ?? [];
        $messageField = $configuration['message_field'] ?? null;

        $message = $messageField !== null && isset($payload[$messageField])
            ? (string) $payload[$messageField]
            : (string) json_encode($payload);

        $response = new ProcessAgentChatAction(
            agent: $agent,
            session: null,
            message: $message,
            app: $this->receiver->app,
            company: $this->receiver->company,
            user: $this->receiver->user,
        )->execute();

        return [
            'message' => 'Agent processed successfully',
            'agent_id' => $agent->getId(),
            'response' => $response,
        ];
    }
}
