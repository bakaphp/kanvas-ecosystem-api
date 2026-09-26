<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\Actions\PrewarmCodingImageAction;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Configures an app (and optionally a machine) to run coding sessions, and says what is still missing.
 *
 * It exists because the alternative was tinker. Everything it writes is a setting or a machine row —
 * nothing here is state the application could not be told through its own surfaces later.
 */
class SetupCodingRuntimeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:setup
        {--app= : App id to configure}
        {--machine= : Agent machine id that will run the containers}
        {--image=kanvas/opencode:1.18.32 : Pinned runtime image}
        {--network= : Docker network the app can reach containers on, e.g. kanvas-ecosystem-api_sail}
        {--provider=openai : Provider id as opencode resolves it}
        {--model=gpt-4.1 : Model id to pin}
        {--api-key-env=OPENAI_API_KEY : Env var the container reads its key from}
        {--api-key= : The provider key to store (company-scoped when --company is given)}
        {--company= : Store the key against this company instead of the app}
        {--workspace-root=/srv/kanvas : Where mirrors, worktrees and session data live on the machine}
        {--static-endpoint= : Attach to an already-running server instead of launching containers}
        {--static-password= : That server\'s OPENCODE_SERVER_PASSWORD}
        {--prewarm : Pull the image onto the machine now}';

    protected $description = 'Configure the self-hosted coding runtime for an app, and report what is missing.';

    public function handle(): int
    {
        $app = $this->resolveApp();

        if ($app === null) {
            $this->error('Pass --app with a valid app id.');

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        $this->writeSettings($app);
        $machine = $this->configureMachine();

        if ($this->option('prewarm') && $machine !== null) {
            $this->prewarm($machine, $app);
        }

        return $this->report($app, $machine);
    }

    private function writeSettings(Apps $app): void
    {
        $settings = [
            ConfigurationEnum::IMAGE->value => $this->option('image'),
            ConfigurationEnum::NETWORK->value => $this->option('network'),
            ConfigurationEnum::PROVIDER_ID->value => $this->option('provider'),
            ConfigurationEnum::MODEL->value => $this->option('model'),
            ConfigurationEnum::PROVIDER_ENV_VAR->value => $this->option('api-key-env'),
            ConfigurationEnum::WORKSPACE_ROOT->value => $this->option('workspace-root'),
            ConfigurationEnum::STATIC_ENDPOINT->value => $this->option('static-endpoint'),
            ConfigurationEnum::STATIC_PASSWORD->value => $this->option('static-password'),
        ];

        foreach ($settings as $key => $value) {
            if ($value !== null && $value !== '') {
                $app->set($key, $value);
            }
        }

        $apiKey = (string) $this->option('api-key');

        if ($apiKey !== '') {
            // Company-scoped when asked, so one tenant's key never becomes everyone's default.
            $holder = $this->resolveCompany() ?? $app;
            $holder->set(ConfigurationEnum::PROVIDER_API_KEY->value, $apiKey);
            $this->line('Stored the provider key against ' . ($holder instanceof Companies ? 'company' : 'app') . '.');
        }
    }

    private function configureMachine(): ?AgentMachine
    {
        $machineId = $this->option('machine');

        if ($machineId === null) {
            return null;
        }

        /** @var AgentMachine|null $machine */
        $machine = AgentMachine::query()->where('id', (int) $machineId)->first();

        if ($machine === null) {
            $this->error('Machine ' . $machineId . ' not found.');

            return null;
        }

        $network = (string) $this->option('network');

        // shared_network is the "host it next to us" shape: nothing is published, and the app reaches
        // the container by name. Without a network there is nothing to join, so it stays ssh_exec.
        $machine->network_mode = $network !== ''
            ? MachineNetworkModeEnum::SHARED_NETWORK->value
            : MachineNetworkModeEnum::SSH_EXEC->value;
        $machine->docker_network = $network !== '' ? $network : null;
        $machine->saveOrFail();

        $this->line('Machine ' . $machine->name . ' set to ' . $machine->network_mode
            . ($network !== '' ? ' on ' . $network : ''));

        return $machine;
    }

    private function prewarm(AgentMachine $machine, Apps $app): void
    {
        try {
            $image = new PrewarmCodingImageAction($machine, $app)->execute();
            $this->info('Pulled ' . $image . ' onto ' . $machine->name);
        } catch (Throwable $e) {
            $this->warn('Prewarm failed: ' . $e->getMessage());
        }
    }

    private function report(Apps $app, ?AgentMachine $machine): int
    {
        $staticEndpoint = (string) $app->get(ConfigurationEnum::STATIC_ENDPOINT->value);
        $missing = [];

        if ($staticEndpoint === '' && $machine === null) {
            $missing[] = 'no machine and no --static-endpoint: there is nowhere to run a session';
        }

        if ((string) ($app->get(ConfigurationEnum::PROVIDER_API_KEY->value) ?? '') === '' && $this->option('api-key') === null) {
            $missing[] = 'no provider key stored — the container will start and every turn will fail';
        }

        $this->newLine();
        $this->line('<info>Coding runtime for app ' . $app->getId() . '</info>');
        $this->line('  image      ' . (string) $app->get(ConfigurationEnum::IMAGE->value));
        $this->line('  model      ' . (string) $app->get(ConfigurationEnum::PROVIDER_ID->value)
            . '/' . (string) $app->get(ConfigurationEnum::MODEL->value));
        $this->line('  mode       ' . ($staticEndpoint !== '' ? 'attach → ' . $staticEndpoint : 'launch'));
        $this->line('  machine    ' . ($machine?->name ?? '—'));

        foreach ($missing as $problem) {
            $this->warn('  ⚠ ' . $problem);
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveApp(): ?Apps
    {
        $appId = $this->option('app');

        return $appId === null ? null : Apps::query()->where('id', (int) $appId)->first();
    }

    private function resolveCompany(): ?Companies
    {
        $companyId = $this->option('company');

        return $companyId === null ? null : Companies::query()->where('id', (int) $companyId)->first();
    }
}
