<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * The runtime config a coding container starts with, written as `opencode.json` in the workspace.
 *
 * **It has to be a project-level file.** All three delivery routes register the provider and show it on
 * `/config`, but only the project file actually resolves a model — `OPENCODE_CONFIG_CONTENT` and a
 * global `~/.config/opencode/opencode.json` both end in `ModelUnavailableError`. That cost several
 * hours to pin down, so do not "simplify" this back into an environment variable.
 *
 * Writing into the workspace would normally show up in the agent's diff; the provisioner adds the file
 * to the worktree's `.git/info/exclude` so git never reports it.
 *
 * The provider is always declared explicitly, never left to the one opencode auto-detects from the API
 * key: the auto-detected provider resolves, reports itself authenticated in `opencode auth list`, and
 * then sends its request with no Authorization header — a 401 that reads like a bad key and is not one.
 *
 * Which adapter is a setting, because it decides which models are reachable at all.
 * `@ai-sdk/openai-compatible` speaks chat completions and takes a baseURL, so it serves any OpenAI-shaped
 * endpoint. `@ai-sdk/openai` speaks the **Responses API**, which is the only way to reach the codex
 * models — they refuse chat completions outright.
 */
class SessionConfigBuilder
{
    /**
     * Chat completions, and anything OpenAI-shaped. `@ai-sdk/openai` is the other option and is
     * required for the codex models, which only exist behind the Responses API.
     */
    private const string DEFAULT_PROVIDER_NPM = '@ai-sdk/openai-compatible';

    /**
     * Deny by default. Everything allowed here is either read-only or the repository's own toolchain —
     * the agent gets to run the tests it wrote, and nothing else.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_BASH_RULES = [
        '*' => 'deny',
        'git *' => 'allow',
        'ls *' => 'allow',
        'cat *' => 'allow',
        'grep *' => 'allow',
        'find *' => 'allow',
        'php *' => 'allow',
        'composer *' => 'allow',
        'npm *' => 'allow',
        'node *' => 'allow',
        './vendor/bin/*' => 'allow',
        'vendor/bin/*' => 'allow',
    ];

    /**
     * @param list<string> $instructionFiles paths INSIDE the container to load as extra rules
     */
    public function __construct(
        private readonly AppInterface $app,
        private readonly ?CodingRepository $repository = null,
        private readonly array $instructionFiles = [],
        private readonly ?Agent $agent = null,
    ) {
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'permission' => $this->permissions(),
            'instructions' => $this->instructions(),
        ];

        $provider = $this->provider();
        $model = $this->pinnedModel();

        // Pinning a model whose provider was never declared produces a config opencode accepts, serves
        // on /config, and then fails every turn with "ModelUnavailableError" — pointing nowhere near
        // the missing setting. Refuse to write that file at all.
        if ($model !== null && $provider === null) {
            throw new ValidationException(
                'Cannot pin ' . $model . ': the provider cannot be declared. With '
                . self::DEFAULT_PROVIDER_NPM . ' you must also set '
                . ConfigurationEnum::PROVIDER_BASE_URL->value . ' (for OpenAI, https://api.openai.com/v1).'
            );
        }

        if ($provider !== null) {
            $config['provider'] = $provider;
        }

        if ($model !== null) {
            $config['model'] = $model;
            $config['experimental'] = ['policies' => $this->providerPolicies()];
        }

        return $config;
    }

    /**
     * `provider/model`, matching what the session is pinned to. A mismatch between this and the
     * session row is what the poller's substitution guard exists to catch.
     */
    private function pinnedModel(): ?string
    {
        return new CodingModelResolver($this->app, $this->agent)->reference();
    }

    /**
     * The provider block, declared openai-compatible against whatever base URL the app configured.
     * `env` names the variable holding the key — the key itself never enters this file, only the
     * container's environment.
     *
     * @return array<string, mixed>|null
     */
    private function provider(): ?array
    {
        $resolver = new CodingModelResolver($this->app, $this->agent);
        $id = $resolver->provider();
        $model = $resolver->model();
        $baseUrl = Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_BASE_URL->value));
        $envVar = Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_ENV_VAR->value))
            ?? 'OPENAI_API_KEY';
        $npm = Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_NPM->value))
            ?? self::DEFAULT_PROVIDER_NPM;

        // Only the compatible adapter needs to be told where to talk: OpenAI's own package knows, and
        // pinning a baseURL on it is how you end up sending Responses API calls somewhere that has none.
        $needsBaseUrl = $npm === self::DEFAULT_PROVIDER_NPM;

        if ($id === null || $model === null || ($needsBaseUrl && $baseUrl === null)) {
            return null;
        }

        $provider = [
            'name' => $id,
            'npm' => $npm,
            'env' => [$envVar],
            'models' => [$model => ['name' => $model]],
        ];

        if ($baseUrl !== null && $needsBaseUrl) {
            $provider['options'] = ['baseURL' => $baseUrl];
        }

        return [$id => $provider];
    }

    /**
     * Extra rule files, merged with whatever `AGENTS.md` the repository already has.
     *
     * `.claude/CLAUDE.md` is listed explicitly because opencode looks for `CLAUDE.md` at the root and
     * ours does not live there — without this the house rules the repo already documents are ignored.
     * Kanvas-side context (a previous session's handoff, repository memory) is written into the session
     * volume and named here, which keeps it out of the worktree and therefore out of the agent's diff.
     *
     * @return list<string>
     */
    private function instructions(): array
    {
        return [
            // The repository's own, whichever it uses.
            'AGENTS.md',
            'CLAUDE.md',
            '.claude/CLAUDE.md',
            // Kanvas-owned, written per session, git-excluded.
            '.kanvas/agent.md',
            '.kanvas/context.md',
            ...$this->instructionFiles,
        ];
    }

    /**
     * A hard stop on the substitution problem rather than a check after the fact: every provider is
     * denied except the one this app pinned, so a misconfigured session cannot quietly answer from
     * opencode's own hosted model with the tenant's code in the prompt. The poller's `model.id`
     * assertion stays as the backstop — this is the lock, that is the alarm.
     *
     * @return list<array<string, string>>
     */
    private function providerPolicies(): array
    {
        $provider = Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_ID->value));

        if ($provider === null) {
            return [];
        }

        // Last matching rule wins, so the deny-all comes first.
        return [
            ['effect' => 'deny', 'action' => 'provider.use', 'resource' => '*'],
            ['effect' => 'allow', 'action' => 'provider.use', 'resource' => $provider],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissions(): array
    {
        $bash = self::DEFAULT_BASH_RULES;

        // A repository's protected paths are enforced again on the diff before anything is pushed;
        // denying the obvious shell routes to them just removes the easy way round.
        foreach ($this->repository?->protectedPaths ?? [] as $path) {
            $bash['* ' . $path . '*'] = 'deny';
        }

        return [
            'edit' => 'allow',
            // The container has no egress restrictions yet, so the agent does not get a fetch tool.
            'webfetch' => 'deny',
            'bash' => $bash,
        ];
    }
}
