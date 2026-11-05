<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use App\Console\Commands\Concerns\ResolvesAppOption;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\Actions\ConfigureCodingMachineAction;
use Kanvas\Connectors\OpenCode\Actions\PrewarmCodingImageAction;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeReadinessService;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Configures an app (and optionally a machine) to run coding sessions, and says what is still missing.
 * Everything it writes is a setting or a machine row — nothing here is state the application could not
 * be told through its own surfaces later.
 */
class SetupCodingRuntimeCommand extends Command
{
    use KanvasJobsTrait;
    use ResolvesAppOption;

    protected $signature = 'kanvas:coding:setup
        {--app= : App id to configure}
        {--machine= : Agent machine id that will run the coding runtime}
        {--runtime= : container (one Docker container per agent) or host (one native opencode process per agent, under launchd on a Mac); omitted keeps the machine\'s current runtime}
        {--host-tools= : Comma list of tool sets a host advertises, e.g. xcode}
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
        $machine = $this->configureMachine($app);

        if ($this->option('prewarm') && $machine !== null) {
            $this->prewarm($machine);
        }

        return $this->report($app, $machine);
    }

    private function writeSettings(Apps $app): void
    {
        $settings = [
            ConfigurationEnum::IMAGE->value => CodingImageService::resolveTag($this->option('image')),
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

    private function configureMachine(Apps $app): ?AgentMachine
    {
        $machineId = $this->option('machine');

        if ($machineId === null) {
            return null;
        }

        /** @var AgentMachine|null $machine */
        $machine = AgentMachine::query()->where('id', (int) $machineId)->fromApp($app)->notDeleted()->first();

        if ($machine === null) {
            $this->error('Machine ' . $machineId . ' not found.');

            return null;
        }

        $runtimeOption = Str::trimToNull((string) $this->option('runtime'));
        $runtime = $runtimeOption === null ? null : MachineRuntimeEnum::tryFrom($runtimeOption);

        if ($runtimeOption !== null && $runtime === null) {
            $this->error('--runtime must be container or host.');

            return null;
        }

        $hostTools = Str::trimToNull((string) $this->option('host-tools'));

        new ConfigureCodingMachineAction(
            $machine,
            runtime: $runtime,
            dockerNetwork: Str::trimToNull((string) $this->option('network')),
            hostTools: $hostTools === null ? null : Str::commaList($hostTools),
        )->execute();

        $this->line('Machine ' . $machine->name . ' set to ' . $machine->runtime . ' runtime, ' . $machine->network_mode
            . ($machine->docker_network !== null ? ' on ' . $machine->docker_network : '')
            . ($hostTools !== null ? ', host tools ' . $hostTools : ''));

        return $machine;
    }

    private function prewarm(AgentMachine $machine): void
    {
        try {
            $image = new PrewarmCodingImageAction($machine)->execute();
            $this->info('Installed ' . $image . ' onto ' . $machine->name);
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

        // Same checklist the agent's own readiness tool reads, so an operator and an agent never get
        // two different answers about the same app. The company matters because --company stores the
        // key there, where an app-only lookup would not find it.
        $readiness = new CodingRuntimeReadinessService();
        $missing = [
            ...$missing,
            ...$readiness->failures($readiness->checksForApp($app, $this->resolveCompany())),
        ];

        if ($machine !== null) {
            $missing = [...$missing, ...$readiness->hostProblems($machine, $app)];
        }

        $this->newLine();
        $this->line('<info>Coding runtime for app ' . $app->getId() . '</info>');
        $this->line('  image      ' . (string) $app->get(ConfigurationEnum::IMAGE->value));
        $this->line('  model      ' . (string) $app->get(ConfigurationEnum::PROVIDER_ID->value)
            . '/' . (string) $app->get(ConfigurationEnum::MODEL->value));
        $this->line('  mode       ' . ($staticEndpoint !== '' ? 'attach → ' . $staticEndpoint : 'launch'));
        $this->line('  machine    ' . ($machine === null ? '—' : $machine->name . ' (' . $machine->runtimeMode()->value . ')'));

        foreach ($missing as $problem) {
            $this->warn('  ⚠ ' . $problem);
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveCompany(): ?Companies
    {
        $companyId = $this->option('company');

        return $companyId === null ? null : Companies::query()->where('id', (int) $companyId)->first();
    }
}
