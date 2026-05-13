<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;
use ValueError;

/**
 * Provider-agnostic agent deployment mutations.
 *
 * Routes by `agent_deployments.provider` (terminate) or the `provider` input field (launch),
 * dispatching to the correct provider-specific job via AgentProviderEnum. This is the canonical
 * entry point — clients no longer need to pick `openclawLaunchAgent` vs `hermesLaunchAgent` etc.
 * (The per-provider mutations remain for backwards compatibility.)
 */
class AgentDeploymentMutation
{
    public function launch(mixed $root, array $args): AgentDeployment
    {
        $app = app(Apps::class);

        /** @var array<string, mixed> $input */
        $input = $args['input'];

        /** @var Agent $agent */
        $agent = Agent::getById((int) $input['agent_id'], $app);
        $company = $agent->company;

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp(
            (int) $input['machine_id'],
            $company,
            $app,
        );

        $provider = AgentProviderEnum::from(strtolower((string) ($input['provider'] ?? 'openclaw')));

        return $provider->dispatchDeployment($agent, $machine, $app, $company)->execute();
    }

    public function terminate(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        try {
            $provider = AgentProviderEnum::from((string) $deployment->provider);
        } catch (Throwable) {
            throw new ValidationException(
                'Cannot terminate deployment — unknown provider: ' . (string) ($deployment->provider ?? '<null>')
            );
        }

        $provider->dispatchTermination($deployment);

        return true;
    }

    /**
     * Set Slack channel tokens for an agent. If `provider` is given, writes only that
     * provider's custom fields; if omitted, writes to ALL supported providers so the
     * agent can be redeployed across runtimes without re-entering credentials.
     */
    public function setSlackTokens(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $botToken = (string) $request['slack_bot_token'];
        $appToken = (string) $request['slack_app_token'];

        foreach ($this->resolveTargetProviders($request) as $provider) {
            $provider->dispatchSetSlackTokens($agent, $botToken, $appToken);
        }

        return true;
    }

    /**
     * Set the Telegram bot token for an agent. Same provider-targeting semantics as
     * setSlackTokens — omit `provider` to write to every runtime's custom field.
     */
    public function setTelegramToken(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $botToken = (string) $request['telegram_bot_token'];

        foreach ($this->resolveTargetProviders($request) as $provider) {
            $provider->dispatchSetTelegramToken($agent, $botToken);
        }

        return true;
    }

    /**
     * If `provider` was supplied on the request, return just that one. Otherwise return every
     * runtime-capable provider — so the caller's tokens land in all providers' custom field
     * sets, letting an agent freely swap between OpenClaw and Hermes without losing creds.
     *
     * @return list<AgentProviderEnum>
     */
    private function resolveTargetProviders(array $request): array
    {
        if (! empty($request['provider'])) {
            try {
                return [AgentProviderEnum::from(strtolower((string) $request['provider']))];
            } catch (ValueError) {
                throw new ValidationException(
                    'Unknown provider: ' . (string) $request['provider']
                );
            }
        }

        return [AgentProviderEnum::OPENCLAW, AgentProviderEnum::HERMES];
    }
}
