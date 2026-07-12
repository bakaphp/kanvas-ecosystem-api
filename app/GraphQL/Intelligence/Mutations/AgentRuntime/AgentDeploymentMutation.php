<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\AgentRuntime;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Kanvas\NervousSystem\Plan\Jobs\SyncDeploymentKanbanJob;
use ValueError;

class AgentDeploymentMutation
{
    public function launch(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $input['machine_id'], $company, $app);

        $provider = $this->resolveLaunchProvider($agent, $input['provider'] ?? null);

        return $provider->dispatchDeployment($agent, $machine, $app, $company);
    }

    /**
     * Retry a failed deployment on the same agent + machine it already targeted.
     *
     * `dispatchDeployment` (via BaseDispatchAgentDeploymentAction) already looks up
     * the existing row by (agent_machine_id, system_user) and reuses it — resetting
     * status to `provisioning`, clearing error_message, and reassigning ports — so
     * this doesn't need any new dedup logic. It just re-derives agent/machine from
     * the failed deployment itself (rather than asking the caller to resupply them)
     * and reuses the provider that deployment is actually pinned to, not whatever
     * the agent's current default happens to be.
     */
    public function retry(mixed $root, array $request): AgentDeployment
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        if ($deployment->status !== DeploymentStatusEnum::FAILED->value) {
            throw new ValidationException('Only a failed deployment can be retried.');
        }

        $agent = $deployment->agent;
        $machine = $deployment->machine;

        if ($agent === null || $machine === null) {
            throw new ValidationException('Cannot retry: the agent or machine no longer exists.');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return AgentRuntimeProviderFactory::forDeployment($deployment)
            ->dispatchDeployment($agent, $machine, $app, $company);
    }

    public function terminate(mixed $root, array $request): bool
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);
        AgentRuntimeProviderFactory::forDeployment($deployment)->dispatchTermination($deployment);

        return true;
    }

    public function delete(mixed $root, array $request): bool
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        if ($deployment->status !== DeploymentStatusEnum::TERMINATED->value) {
            AgentRuntimeProviderFactory::forDeployment($deployment)->dispatchTermination($deployment);
        }

        return (bool) $deployment->delete();
    }

    public function restart(mixed $root, array $request): bool
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);
        AgentRuntimeProviderFactory::forDeployment($deployment)->dispatchRestart($deployment);

        return true;
    }

    public function logs(mixed $root, array $request): string
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);
        $lines = (int) ($request['lines'] ?? 100);

        // 30s TTL — log poll endpoints can hit several times per minute from the UI, and
        // each call is an SSH round-trip. Cached value is raw text; key is provider-agnostic.
        $cacheKey = 'agent-runtime:container-logs:' . $deployment->id . ':' . $lines;

        return Cache::remember(
            $cacheKey,
            30,
            fn (): string => AgentRuntimeProviderFactory::forDeployment($deployment)->fetchContainerLogs($deployment, $lines),
        );
    }

    public function status(mixed $root, array $request): AgentDeployment
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        $cacheKey = 'agent-runtime:container-status:' . $deployment->id;

        return Cache::remember(
            $cacheKey,
            30,
            fn (): AgentDeployment => AgentRuntimeProviderFactory::forDeployment($deployment)->fetchContainerStatus($deployment),
        );
    }

    public function collectUsage(mixed $root, array $request): AgentUsageSnapshot
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        return AgentRuntimeProviderFactory::forDeployment($deployment)->collectUsage($deployment, $app, $company);
    }

    public function collectSessionTranscripts(mixed $root, array $request): int
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        $since = isset($request['since']) ? Carbon::parse((string) $request['since']) : null;

        return AgentRuntimeProviderFactory::forDeployment($deployment)
            ->collectSessionTranscripts(
                $deployment,
                $app,
                $company,
                $since
            );
    }

    public function syncKanban(mixed $root, array $request): bool
    {
        $deployment = $this->loadDeployment((int) $request['deployment_id']);

        SyncDeploymentKanbanJob::dispatch($deployment);

        return true;
    }

    public function setSlackTokens(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $botToken = (string) $request['slack_bot_token'];
        $appToken = (string) $request['slack_app_token'];

        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, $botToken);
        $agent->set(AgentChannelTokenEnum::SLACK_APP_TOKEN->value, $appToken);

        $this->syncLiveCredentials($agent);

        return true;
    }

    public function setTelegramToken(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        $agent->set(AgentChannelTokenEnum::TELEGRAM_BOT_TOKEN->value, (string) $request['telegram_bot_token']);

        // Optional allow-list — without at least one Telegram user ID, Hermes denies every
        // inbound message. Accept it on the same call so admins don't need a follow-up.
        if (isset($request['telegram_allowed_users'])) {
            $agent->set(
                AgentChannelTokenEnum::TELEGRAM_ALLOWED_USERS->value,
                (string) $request['telegram_allowed_users'],
            );
        }

        $this->syncLiveCredentials($agent);

        return true;
    }

    /**
     * Channel tokens are baked into the container's docker-compose.yml as env vars at launch,
     * so setting them on the agent alone never reaches an already-running deployment. When the
     * agent has a live deployment, push the rotated keys to it — the provider recreates the
     * container (`docker compose up -d`) so the new env applies. No-op pre-launch.
     */
    private function syncLiveCredentials(Agent $agent): void
    {
        $deployment = $agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            return;
        }

        AgentRuntimeProviderFactory::forDeployment(
            $deployment
        )->dispatchCredentialSync($deployment);
    }

    public function execCommand(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return AgentRuntimeProviderFactory::forDeployment($deployment)->execCommand(
            $deployment,
            (string) $request['command'],
            (string) $request['session_id'],
        );
    }

    public function getConfig(mixed $root, array $request): string
    {
        $app = app(Apps::class);
        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return AgentRuntimeProviderFactory::forDeployment($deployment)->fetchConfig($deployment);
    }

    public function updateConfig(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getById((int) $request['deployment_id'], $app);

        return AgentRuntimeProviderFactory::forDeployment($deployment)->updateConfig(
            $deployment,
            (string) $request['config'],
        );
    }

    public function backup(mixed $root, array $request): AgentBackup
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $deployment = $this->loadDeployment((int) $request['deployment_id']);
        $includeWorkspace = $request['include_workspace'] ?? true;

        // Create the record immediately so the caller gets an ID to poll
        $backup = new AgentBackup();
        $backup->apps_id = $app->getId();
        $backup->companies_id = $company->getId();
        $backup->agent_deployment_id = $deployment->getId();
        $backup->status = 'pending';
        $backup->saveOrFail();

        AgentRuntimeProviderFactory::forDeployment($deployment)->dispatchBackup(
            $deployment,
            $backup,
            (bool) $includeWorkspace,
        );

        return $backup;
    }

    public function migrateWorkspace(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        $sourceDeployment = $this->loadDeployment((int) $input['source_deployment_id']);

        /** @var AgentMachine $destinationMachine */
        $destinationMachine = AgentMachine::getByIdFromCompanyApp(
            (int) $input['destination_machine_id'],
            $company,
            $app,
        );

        AgentRuntimeProviderFactory::forDeployment($sourceDeployment)->dispatchMigrateWorkspace(
            $sourceDeployment,
            $destinationMachine,
            $app,
            $company,
            $input['source_path'] ?? null,
            $input['destination_path'] ?? null,
        );

        return $sourceDeployment;
    }

    /**
     * Cross-runtime adoption — moves an existing deployment from its current runtime onto
     * `target_provider` running on `destination_machine_id`. The destination provider's
     * implementation reads the source workspace and stands up an equivalent deployment.
     *
     * Today only Hermes implements being a migration target (consumes OpenClaw deployments).
     * OpenClaw inherits the abstract's default-throws, so trying to adopt INTO OpenClaw is
     * rejected with a clear error.
     */
    public function migrateToProvider(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        $sourceDeployment = $this->loadDeployment((int) $input['source_deployment_id']);

        /** @var AgentMachine $destinationMachine */
        $destinationMachine = AgentMachine::getByIdFromCompanyApp(
            (int) $input['destination_machine_id'],
            $company,
            $app,
        );

        try {
            $targetProvider = AgentProviderEnum::from(strtolower((string) $input['target_provider']));
        } catch (ValueError) {
            throw new ValidationException('Unknown target provider: ' . (string) $input['target_provider']);
        }

        AgentRuntimeProviderFactory::forProvider($targetProvider)->dispatchAdoptForeignDeployment(
            $sourceDeployment,
            $destinationMachine,
            $app,
            $company,
            $input['source_path'] ?? null,
            $input['destination_path'] ?? null,
        );

        return $sourceDeployment;
    }

    private function loadDeployment(int $id): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp($id, $company, $app);

        return $deployment;
    }

    private function resolveLaunchProvider(Agent $agent, mixed $explicit): AgentRuntimeProvider
    {
        if (! empty($explicit)) {
            try {
                return AgentRuntimeProviderFactory::forProvider(
                    AgentProviderEnum::from(strtolower((string) $explicit)),
                );
            } catch (ValueError) {
                throw new ValidationException('Unknown provider: ' . (string) $explicit);
            }
        }

        return AgentRuntimeProviderFactory::forAgent($agent);
    }
}
