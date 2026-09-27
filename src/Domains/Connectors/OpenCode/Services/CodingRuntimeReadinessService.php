<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\HashTableInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\PreparesHostDirectory;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesAgentMachine;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingSetupCheck;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Everything that has to be true before a coding job can run, asked in one place.
 *
 * Each of these was learned by dispatching a job and reading the failure off a session row: no image,
 * then an unwritable workspace, then a provider block that could not be declared. One prerequisite per
 * attempt, each error pointing somewhere other than the setting that caused it. That is a memory game,
 * and nobody wins it twice.
 *
 * Every problem names the setting and who sets it, because the two readers are an operator running
 * `kanvas:coding:setup` and an agent that has to tell a person what to fix — neither can act on
 * "misconfigured".
 */
class CodingRuntimeReadinessService
{
    use PreparesHostDirectory;
    use ResolvesAgentMachine;

    private const DEFAULT_WORKSPACE_ROOT = '/srv/kanvas';

    /**
     * Every prerequisite for one agent, passing and failing, with who can fix each failure.
     *
     * The app and company come off the agent rather than the caller. An agent bound to app X cannot
     * run for app Y, so accepting one alongside it would only make it possible to ask about a tenant
     * this agent is not in.
     *
     * Settings with a working fallback are reported as such rather than omitted. An install where
     * nothing is set and one where the defaults are deliberate look identical from a list of failures
     * alone, and the difference is whether anyone still has a step left to do.
     *
     * No SSH — safe inside an agent turn. The machine's own state is `hostProblems()`.
     *
     * @return list<CodingSetupCheck>
     */
    public function checksForAgent(Agent $agent): array
    {
        return [
            ...$this->agentChecks($agent),
            ...$this->appChecks($agent->app, $agent, $agent->company),
        ];
    }

    /**
     * The app-wide half, for `kanvas:coding:setup`, which configures a tenant before any agent exists.
     *
     * @return list<CodingSetupCheck>
     */
    public function checksForApp(AppInterface $app, ?CompanyInterface $company = null): array
    {
        return $this->appChecks($app, null, $company);
    }

    /**
     * @param list<CodingSetupCheck> $checks
     * @return list<string>
     */
    public function failures(array $checks): array
    {
        return array_values(array_map(
            static fn (CodingSetupCheck $check): string => trim($check->problem . ' ' . $check->fix),
            array_filter($checks, static fn (CodingSetupCheck $check): bool => ! $check->ok),
        ));
    }

    /**
     * What is wrong with the machine. Opens one SSH connection, so it is slower than the config half —
     * worth it, because dispatching onto an unprepared host is a failure a person reads minutes later.
     *
     * @return list<string>
     */
    public function hostProblems(AgentMachine $machine, AppInterface $app): array
    {
        try {
            $client = SshClient::fromMachine($machine);
        } catch (Throwable $e) {
            return ['Cannot reach ' . $machine->name . ' over ssh: ' . $e->getMessage()];
        }

        $problems = [];

        try {
            $owner = trim($client->exec('whoami', 30));

            if (trim($client->exec('docker info >/dev/null 2>&1 && echo OK || echo NO', 60)) !== 'OK') {
                $problems[] = 'Docker is not usable by ' . $owner . ' on ' . $machine->name
                    . ' — install it, or add that user to the docker group.';
            }

            try {
                $this->prepareHostDirectory($client, $this->workspaceRoot($app), $machine->name);
            } catch (ValidationException $e) {
                $problems[] = $e->getMessage();
            }

            $image = $this->settingOf($app, ConfigurationEnum::IMAGE);

            if ($image !== null && trim($client->exec('docker images -q ' . escapeshellarg($image), 60)) === '') {
                $problems[] = $image . ' is not on ' . $machine->name . ' — run: php artisan '
                    . 'kanvas:coding:build-image --app=' . $app->getId() . ' --machine=' . $machine->getId() . '.';
            }
        } catch (Throwable $e) {
            $problems[] = 'Could not check ' . $machine->name . ': ' . $e->getMessage();
        } finally {
            $client->disconnect();
        }

        return $problems;
    }

    public function workspaceRoot(AppInterface $app): string
    {
        return rtrim($this->settingOf($app, ConfigurationEnum::WORKSPACE_ROOT) ?? self::DEFAULT_WORKSPACE_ROOT, '/');
    }

    /**
     * The machine a session would land on, or null with the reason recorded as a problem.
     *
     * @param list<string> $problems
     */
    public function machineFor(Agent $agent, array &$problems): ?AgentMachine
    {
        try {
            $machine = $this->configuredMachine($agent) ?? AgentMachine::query()
                ->fromApp($agent->app)
                ->fromCompany($agent->company)
                ->notDeleted()
                ->where('is_active', 1)
                ->first();
        } catch (ValidationException $e) {
            $problems[] = $e->getMessage();

            return null;
        }

        if ($machine === null) {
            $problems[] = 'No machine: set ' . AgentCustomFieldEnum::MACHINE_ID->value . ' on this agent, or '
                . 'activate one for this company.';
        }

        return $machine;
    }

    /**
     * @return list<CodingSetupCheck>
     */
    private function agentChecks(Agent $agent): array
    {
        $token = AgentCustomFieldEnum::GIT_TOKEN->value;
        $machine = AgentCustomFieldEnum::MACHINE_ID->value;
        $model = AgentCustomFieldEnum::MODEL->value;

        return [
            $this->agentSetting($agent, AgentCustomFieldEnum::GIT_TOKEN) === null
                ? CodingSetupCheck::needsAdmin(
                    $token,
                    'agent',
                    'No git token, so a private repository cannot be cloned and nothing can be pushed.',
                    'Ask an administrator to set ' . $token . ' on this agent. Its reach is this agent\'s reach, '
                        . 'so it should be scoped to the repositories this agent may touch.',
                )
                : CodingSetupCheck::pass($token, 'agent'),

            $this->agentSetting($agent, AgentCustomFieldEnum::MACHINE_ID) === null
                ? CodingSetupCheck::usingDefault(
                    $machine,
                    'agent',
                    'any active machine belonging to this company — fine with one, arbitrary with several',
                )
                : CodingSetupCheck::pass($machine, 'agent'),

            $this->agentSetting($agent, AgentCustomFieldEnum::MODEL) === null
                ? CodingSetupCheck::usingDefault($model, 'agent', 'the app\'s ' . ConfigurationEnum::MODEL->value)
                : CodingSetupCheck::pass($model, 'agent'),
        ];
    }

    /**
     * @return list<CodingSetupCheck>
     */
    private function appChecks(AppInterface $app, ?Agent $agent, ?CompanyInterface $company): array
    {
        $checks = [];

        foreach ([ConfigurationEnum::IMAGE, ConfigurationEnum::PROVIDER_ID] as $setting) {
            $checks[] = $this->settingOf($app, $setting) === null
                ? $this->runSetup($app, $setting)
                : CodingSetupCheck::pass($setting->value, 'app');
        }

        $checks[] = $this->modelCheck($app, $agent);
        $checks[] = $this->keyCheck($app, $agent, $company);
        $checks = [...$checks, ...$this->transportChecks($app)];

        foreach ([
            [ConfigurationEnum::PROVIDER_ENV_VAR, 'OPENAI_API_KEY'],
            [ConfigurationEnum::WORKSPACE_ROOT, self::DEFAULT_WORKSPACE_ROOT],
        ] as [$setting, $default]) {
            $checks[] = $this->settingOf($app, $setting) === null
                ? CodingSetupCheck::usingDefault($setting->value, 'app', $default)
                : CodingSetupCheck::pass($setting->value, 'app');
        }

        return $checks;
    }

    /** The agent's own model overrides the app's, so a missing app model is not fatal when it has one. */
    private function modelCheck(AppInterface $app, ?Agent $agent): CodingSetupCheck
    {
        $model = ConfigurationEnum::MODEL;

        if ($this->settingOf($app, $model) !== null) {
            return CodingSetupCheck::pass($model->value, 'app');
        }

        $onAgent = $agent === null ? null : $this->agentSetting($agent, AgentCustomFieldEnum::MODEL);

        return $onAgent === null
            ? $this->runSetup($app, $model)
            : CodingSetupCheck::usingDefault(
                $model->value,
                'app',
                'this agent\'s own ' . AgentCustomFieldEnum::MODEL->value . ' (' . $onAgent . ')',
            );
    }

    private function keyCheck(AppInterface $app, ?Agent $agent, ?CompanyInterface $company): CodingSetupCheck
    {
        $key = ConfigurationEnum::PROVIDER_API_KEY->value;
        $scope = $this->resolvedKey($app, $agent, $company);

        return $scope === null
            ? CodingSetupCheck::needsAdmin(
                $key,
                'agent, company or app',
                'No provider API key anywhere. The container starts and every turn fails at the provider.',
                'Ask an administrator for the key, then set it — narrowest first: '
                    . AgentCustomFieldEnum::PROVIDER_API_KEY->value . ' on the agent for a machine you do '
                    . 'not own, or --api-key on kanvas:coding:setup for one you do.',
            )
            : CodingSetupCheck::pass($key, $scope);
    }

    /**
     * How opencode reaches the provider. The compatible adapter has to be told where to talk;
     * `@ai-sdk/openai` already knows. With neither, `SessionConfigBuilder` cannot declare the provider
     * and refuses to write the config — so the pair fails as one item rather than twice.
     *
     * @return list<CodingSetupCheck>
     */
    private function transportChecks(AppInterface $app): array
    {
        $npm = ConfigurationEnum::PROVIDER_NPM;
        $base = ConfigurationEnum::PROVIDER_BASE_URL;
        $npmValue = $this->settingOf($app, $npm);
        $baseValue = $this->settingOf($app, $base);

        if ($npmValue === null && $baseValue === null) {
            return [
                CodingSetupCheck::needsCommand(
                    $npm->value . ' or ' . $base->value,
                    'app',
                    'Neither ' . $npm->value . ' nor ' . $base->value . ' is set, so the provider cannot be '
                        . 'declared and every turn fails with ModelUnavailableError.',
                    'Set ' . $npm->value . ' to @ai-sdk/openai — its Responses API is what codex and '
                        . 'gpt-6-luna tool calls need. Or set ' . $base->value
                        . ' to https://api.openai.com/v1 to keep the compatible adapter.',
                ),
            ];
        }

        return [
            $npmValue !== null
                ? CodingSetupCheck::pass($npm->value, 'app')
                : CodingSetupCheck::usingDefault(
                    $npm->value,
                    'app',
                    '@ai-sdk/openai-compatible, pointed at ' . $baseValue,
                ),
            $baseValue !== null
                ? CodingSetupCheck::pass($base->value, 'app')
                : CodingSetupCheck::usingDefault(
                    $base->value,
                    'app',
                    'none needed — ' . $npmValue . ' carries its own endpoint',
                ),
        ];
    }

    private function runSetup(AppInterface $app, ConfigurationEnum $setting): CodingSetupCheck
    {
        return CodingSetupCheck::needsCommand(
            $setting->value,
            'app',
            $setting->value . ' is not configured on the app.',
            'Run: php artisan kanvas:coding:setup --app=' . $app->getId(),
        );
    }

    private function agentSetting(Agent $agent, AgentCustomFieldEnum $field): ?string
    {
        return Str::trimToNull((string) $agent->get($field->value));
    }

    /** Apps and Companies are both `HashTableInterface`, so one read serves either scope. */
    private function settingOf(HashTableInterface $holder, ConfigurationEnum $setting): ?string
    {
        return Str::trimToNull((string) $holder->get($setting->value));
    }

    private function resolvedKey(AppInterface $app, ?Agent $agent, ?CompanyInterface $company): ?string
    {
        if ($agent !== null && $this->agentSetting($agent, AgentCustomFieldEnum::PROVIDER_API_KEY) !== null) {
            return 'agent';
        }

        if ($company !== null && $this->settingOf($company, ConfigurationEnum::PROVIDER_API_KEY) !== null) {
            return 'company';
        }

        return $this->settingOf($app, ConfigurationEnum::PROVIDER_API_KEY) === null ? null : 'app';
    }
}
