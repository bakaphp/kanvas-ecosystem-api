<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use BackedEnum;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\HashTableInterface;
use Baka\Support\Str;
use Closure;
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
        $ownProvider = $agent !== null && new CodingModelResolver($app, $agent)->hasOwnProvider();

        $checks[] = $this->settingOf($app, ConfigurationEnum::IMAGE) === null
            ? $this->runSetup($app, ConfigurationEnum::IMAGE)
            : CodingSetupCheck::pass(ConfigurationEnum::IMAGE->value, 'app');

        if ($ownProvider) {
            $checks[] = CodingSetupCheck::pass(AgentCustomFieldEnum::PROVIDER_ID->value, 'agent');
        } else {
            $checks[] = $this->settingOf($app, ConfigurationEnum::PROVIDER_ID) === null
                ? $this->runSetup($app, ConfigurationEnum::PROVIDER_ID)
                : CodingSetupCheck::pass(ConfigurationEnum::PROVIDER_ID->value, 'app');
        }

        $checks[] = $this->modelCheck($app, $agent);
        $checks[] = $this->keyCheck($app, $agent, $company);
        $checks = [
            ...$checks,
            ...($ownProvider
                ? $this->transportChecks(
                    'agent',
                    [
                        AgentCustomFieldEnum::PROVIDER_NPM,
                        AgentCustomFieldEnum::PROVIDER_BASE_URL,
                        AgentCustomFieldEnum::PROVIDER_ENV_VAR,
                    ],
                    fn (AgentCustomFieldEnum $field): ?string => $this->agentSetting($agent, $field),
                )
                : $this->transportChecks(
                    'app',
                    [
                        ConfigurationEnum::PROVIDER_NPM,
                        ConfigurationEnum::PROVIDER_BASE_URL,
                        ConfigurationEnum::PROVIDER_ENV_VAR,
                    ],
                    fn (ConfigurationEnum $setting): ?string => $this->settingOf($app, $setting),
                )),
        ];

        $root = ConfigurationEnum::WORKSPACE_ROOT;
        $checks[] = $this->settingOf($app, $root) === null
            ? CodingSetupCheck::usingDefault($root->value, 'app', self::DEFAULT_WORKSPACE_ROOT)
            : CodingSetupCheck::pass($root->value, 'app');

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

        if ($agent !== null && new CodingModelResolver($app, $agent)->hasOwnProvider()) {
            return $this->agentHasOwnKey($agent)
                ? CodingSetupCheck::pass(AgentCustomFieldEnum::PROVIDER_API_KEY->value, 'agent')
                : CodingSetupCheck::needsAdmin(
                    AgentCustomFieldEnum::PROVIDER_API_KEY->value,
                    'agent',
                    'This agent has its own provider but no key of its own, and the tenant key is never '
                        . 'sent to a provider it was not issued by.',
                    'Set ' . AgentCustomFieldEnum::PROVIDER_API_KEY->value . ' or '
                        . AgentCustomFieldEnum::PROVIDER_KEY_NAME->value . ' on the agent.',
                );
        }

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
     * How opencode reaches the provider — the app's settings, or an agent's own. The compatible adapter
     * has to be told where to talk; `@ai-sdk/openai` already knows. Without a base URL on the compatible
     * adapter, `SessionConfigBuilder` cannot declare the provider and refuses to write the config.
     *
     * @param array{0: BackedEnum, 1: BackedEnum, 2: BackedEnum} $fields npm, base URL, env var
     * @param Closure(BackedEnum): ?string $read
     * @return list<CodingSetupCheck>
     */
    private function transportChecks(string $scope, array $fields, Closure $read): array
    {
        [$npm, $base, $envVar] = $fields;
        $npmValue = $read($npm);
        $baseValue = $read($base);
        $compatible = $npmValue === null || $npmValue === CodingModelResolver::DEFAULT_NPM;

        return [
            $npmValue !== null
                ? CodingSetupCheck::pass($npm->value, $scope)
                : CodingSetupCheck::usingDefault($npm->value, $scope, CodingModelResolver::DEFAULT_NPM),
            match (true) {
                $baseValue !== null => CodingSetupCheck::pass($base->value, $scope),
                $compatible => CodingSetupCheck::needsCommand(
                    $base->value,
                    $scope,
                    'No base URL for ' . CodingModelResolver::DEFAULT_NPM . ', so the provider cannot be '
                        . 'declared and every turn fails with ModelUnavailableError.',
                    'Set ' . $base->value . ' (https://api.openai.com/v1, https://openrouter.ai/api/v1, ...), or '
                        . $npm->value . ' to @ai-sdk/openai — its Responses API is what codex and gpt-6-luna '
                        . 'tool calls need.',
                ),
                default => CodingSetupCheck::usingDefault(
                    $base->value,
                    $scope,
                    'none needed — ' . $npmValue . ' carries its own endpoint',
                ),
            },
            $read($envVar) !== null
                ? CodingSetupCheck::pass($envVar->value, $scope)
                : CodingSetupCheck::usingDefault($envVar->value, $scope, CodingModelResolver::DEFAULT_ENV_VAR),
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

    private function agentHasOwnKey(Agent $agent): bool
    {
        return $this->agentSetting($agent, AgentCustomFieldEnum::PROVIDER_API_KEY) !== null
            || $this->agentSetting($agent, AgentCustomFieldEnum::PROVIDER_KEY_NAME) !== null;
    }

    private function resolvedKey(AppInterface $app, ?Agent $agent, ?CompanyInterface $company): ?string
    {
        if ($agent !== null && $this->agentHasOwnKey($agent)) {
            return 'agent';
        }

        if ($company !== null && $this->settingOf($company, ConfigurationEnum::PROVIDER_API_KEY) !== null) {
            return 'company';
        }

        return $this->settingOf($app, ConfigurationEnum::PROVIDER_API_KEY) === null ? null : 'app';
    }
}
