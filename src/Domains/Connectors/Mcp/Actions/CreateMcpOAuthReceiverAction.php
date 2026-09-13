<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Find-or-create the receiver an agent's OAuth MCP connection runs through — one per (agent, server),
 * since the shared OAuthIntegrationController addresses a flow by receiver uuid. App and company come
 * from the agent, so a receiver can never pair an agent with another tenant.
 */
class CreateMcpOAuthReceiverAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Tool $tool,
        private readonly Users $user,
        private readonly ?string $redirectUrl = null,
        private readonly ?string $serverUrl = null,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        $action = WorkflowAction::query()->where('model_name', OAuthCallbackJob::class)->first();

        if (! $action instanceof WorkflowAction) {
            throw new ValidationException('The OAuth callback workflow action is not registered — run kanvas:workflow-sync-actions.');
        }

        $existing = $this->existing($action);

        if ($existing !== null) {
            $existing->configuration = $this->withConnection(
                is_array($existing->configuration) ? $existing->configuration : []
            );
            $existing->saveOrFail();

            return $existing;
        }

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->agent->app,
                company: $this->agent->company,
                user: $this->user,
                action: $action,
                name: sprintf('MCP OAuth · %s · %s', $this->tool->name, $this->agent->name),
                description: sprintf('OAuth connection of the %s MCP server for %s.', $this->tool->name, $this->agent->name),
                configuration: $this->withConnection([
                    'oauth_provider' => 'mcp',
                    'tool_id' => $this->tool->getId(),
                    'agents_id' => $this->agent->getId(),
                ]),
                run_async: false,
            )
        )->execute();
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function withConnection(array $configuration): array
    {
        if ($this->redirectUrl !== null) {
            $configuration['redirect_url'] = $this->redirectUrl;
        }

        if ($this->serverUrl !== null) {
            $configuration['server_url'] = $this->serverUrl;
        } else {
            unset($configuration['server_url']);
        }

        return $configuration;
    }

    /**
     * Matched in PHP rather than by JSON path in SQL, so a receiver whose configuration is not valid
     * JSON cannot fail the lookup for every other receiver in the company.
     */
    private function existing(WorkflowAction $action): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($this->agent->app)
            ->fromCompany($this->agent->company)
            ->where('action_id', $action->getId())
            ->where('is_deleted', 0)
            ->get()
            ->first(function (ReceiverWebhook $receiver): bool {
                $configuration = is_array($receiver->configuration) ? $receiver->configuration : [];

                return ($configuration['oauth_provider'] ?? null) === 'mcp'
                    && (int) ($configuration['tool_id'] ?? 0) === $this->tool->getId()
                    && (int) ($configuration['agents_id'] ?? 0) === $this->agent->getId();
            });
    }
}
