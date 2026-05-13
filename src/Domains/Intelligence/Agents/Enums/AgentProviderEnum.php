<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction as HermesDispatchAction;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum as HermesCustomFieldEnum;
use Kanvas\Connectors\Hermes\Jobs\TerminateAgentJob as HermesTerminateAgentJob;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction as OpenClawDispatchAction;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum as OpenClawCustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Jobs\TerminateAgentJob as OpenClawTerminateAgentJob;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseDispatchAgentDeploymentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use ValueError;

enum AgentProviderEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
    case HERMES = 'hermes';

    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): BaseDispatchAgentDeploymentAction {
        return match ($this) {
            self::OPENCLAW => new OpenClawDispatchAction($agent, $machine, $app, $company),
            self::HERMES => new HermesDispatchAction($agent, $machine, $app, $company),
            default => throw new ValueError("Provider [{$this->value}] does not support agent deployment."),
        };
    }

    /**
     * Dispatch the provider-specific terminate job for an existing deployment. Routing
     * mirrors dispatchDeployment() — keeps both directions of the lifecycle in one place,
     * so callers (GraphQL resolvers, CLI commands, internal cleanup) don't repeat the match.
     */
    public function dispatchTermination(AgentDeployment $deployment): void
    {
        match ($this) {
            self::OPENCLAW => OpenClawTerminateAgentJob::dispatch($deployment),
            self::HERMES => HermesTerminateAgentJob::dispatch($deployment),
            default => throw new ValueError("Provider [{$this->value}] does not support termination."),
        };
    }

    /**
     * Write Slack channel tokens to the provider-specific custom fields on the agent.
     * Each provider reads its own `<PROVIDER>_SLACK_BOT_TOKEN` / `<PROVIDER>_SLACK_APP_TOKEN`
     * custom fields when generating the runtime config; this routes the write so the right
     * fields get populated for whichever runtime the agent will be deployed on.
     */
    public function dispatchSetSlackTokens(Agent $agent, string $botToken, string $appToken): void
    {
        match ($this) {
            self::OPENCLAW => $this->writeSlackTokens(
                $agent,
                OpenClawCustomFieldEnum::SLACK_BOT_TOKEN->value,
                OpenClawCustomFieldEnum::SLACK_APP_TOKEN->value,
                $botToken,
                $appToken,
            ),
            self::HERMES => $this->writeSlackTokens(
                $agent,
                HermesCustomFieldEnum::SLACK_BOT_TOKEN->value,
                HermesCustomFieldEnum::SLACK_APP_TOKEN->value,
                $botToken,
                $appToken,
            ),
            default => throw new ValueError("Provider [{$this->value}] does not support Slack tokens."),
        };
    }

    /**
     * Write the Telegram bot token to the provider-specific custom field on the agent.
     */
    public function dispatchSetTelegramToken(Agent $agent, string $botToken): void
    {
        match ($this) {
            self::OPENCLAW => $agent->set(OpenClawCustomFieldEnum::TELEGRAM_BOT_TOKEN->value, $botToken),
            self::HERMES => $agent->set(HermesCustomFieldEnum::TELEGRAM_BOT_TOKEN->value, $botToken),
            default => throw new ValueError("Provider [{$this->value}] does not support Telegram tokens."),
        };
    }

    private function writeSlackTokens(
        Agent $agent,
        string $botKey,
        string $appKey,
        string $botToken,
        string $appToken,
    ): void {
        $agent->set($botKey, $botToken);
        $agent->set($appKey, $appToken);
    }
}
