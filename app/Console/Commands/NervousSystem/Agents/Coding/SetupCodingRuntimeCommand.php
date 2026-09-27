<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\Actions\PrewarmCodingImageAction;
use Kanvas\Connectors\OpenCode\Concerns\PreparesHostDirectory;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
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
    use PreparesHostDirectory;

    protected $signature = 'kanvas:coding:setup
        {--app= : App id to configure}
        {--machine= : Agent machine id that will run the containers}
        {--image= : Pinned runtime image; defaults to the tag the Dockerfile builds}
        {--network= : Docker network the app can reach containers on, e.g. kanvas-ecosystem-api_sail}
        {--provider=oai : Provider id, as declared in the project config}
        {--model=gpt-6-luna : Model id to pin}
        {--api-key-env=OPENAI_API_KEY : Env var the container reads its key from}
        {--provider-npm= : @ai-sdk/openai for the Responses API (codex, gpt-6-luna tool calls)}
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
            ConfigurationEnum::IMAGE->value => $this->option('image') ?: CodingImageService::pinnedTag(),
            ConfigurationEnum::NETWORK->value => $this->option('network'),
            ConfigurationEnum::PROVIDER_ID->value => $this->option('provider'),
            ConfigurationEnum::MODEL->value => $this->option('model'),
            ConfigurationEnum::PROVIDER_ENV_VAR->value => $this->option('api-key-env'),
            ConfigurationEnum::PROVIDER_NPM->value => $this->option('provider-npm'),
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

    /**
     * What is wrong with the MACHINE, not the settings.
     *
     * Configuration being complete says nothing about the host, and a fresh box fails one prerequisite
     * per dispatch: the container comes up, then the clone cannot write, then the image is not there.
     * Each of those lands on a session row rather than in front of the person who just ran setup. Ask
     * the machine here, while somebody is watching, and name the command that fixes it.
     *
     * Best-effort and never fatal on its own — an unreachable machine is reported, not thrown.
     *
     * @return list<string>
     */
    private function hostProblems(AgentMachine $machine, Apps $app): array
    {
        try {
            $client = SshClient::fromMachine($machine);
        } catch (Throwable $e) {
            return ['cannot ssh to ' . $machine->name . ': ' . $e->getMessage()];
        }

        $problems = [];

        try {
            $owner = trim($client->exec('whoami', 30));

            if (trim($client->exec('docker info >/dev/null 2>&1 && echo OK || echo NO', 60)) !== 'OK') {
                $problems[] = 'docker is not usable by ' . $owner . ' on ' . $machine->name
                    . ' — install it, or add that user to the docker group';
            }

            $root = rtrim((string) ($app->get(ConfigurationEnum::WORKSPACE_ROOT->value) ?? '/srv/kanvas'), '/');

            try {
                // The same preparation a session does, so this both reports and fixes what it can —
                // and collects rather than throws, because the checks below are still worth running.
                $this->prepareHostDirectory($client, $root, $machine->name);
            } catch (ValidationException $e) {
                $problems[] = $e->getMessage();
            }

            $image = (string) $app->get(ConfigurationEnum::IMAGE->value);

            if ($image !== '' && trim($client->exec('docker images -q ' . escapeshellarg($image), 60)) === '') {
                $problems[] = $image . ' is not on ' . $machine->name . ' — run: php artisan '
                    . 'kanvas:coding:build-image --app=' . $app->getId() . ' --machine=' . $machine->getId();
            }
        } catch (Throwable $e) {
            $problems[] = 'could not check ' . $machine->name . ': ' . $e->getMessage();
        } finally {
            $client->disconnect();
        }

        return $problems;
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

        if ($machine !== null) {
            $missing = [...$missing, ...$this->hostProblems($machine, $app)];
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
