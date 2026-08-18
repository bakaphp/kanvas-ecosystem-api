<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Connectors\ClaudeAgent\DataTransferObject\ClaudeAgentSpec;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;

/**
 * Turns a Kanvas Agent row into the remote agent definition, reading the agent's own
 * soul/instructions/output_format and falling back to its type — the same precedence a Neuron
 * handler uses. Never instantiates the handler class: for a hosted provider there isn't one.
 */
class AgentSpecBuilderService
{
    /** Anthropic's current default. Used when the agent has no Claude-compatible model configured. */
    public const DEFAULT_MODEL = 'claude-opus-5';

    /** The full prebuilt sandbox toolset: bash, read, write, edit, glob, grep, web_fetch, web_search. */
    public const AGENT_TOOLSET = 'agent_toolset_20260401';

    /**
     * A repo mount gives git, not the GitHub API — anything API-shaped goes through this server.
     * {@see EnsureGithubVaultAction} explains why, and provisions the credential it needs.
     */
    public const GITHUB_MCP_NAME = 'github';
    public const GITHUB_MCP_URL = 'https://api.githubcopilot.com/mcp/';

    protected ?CustomToolBridgeService $bridge = null;

    public function __construct(
        protected readonly Agent $agent,
        ?CustomToolBridgeService $bridge = null,
    ) {
        $this->bridge = $bridge;
    }

    public function build(): ClaudeAgentSpec
    {
        return new ClaudeAgentSpec(
            name: $this->resolveName(),
            model: $this->resolveModel(),
            system: $this->buildSystemPrompt(),
            description: AgentSettingsService::trimmed($this->agent->description),
            tools: $this->buildTools(),
            mcpServers: $this->buildMcpServers(),
        );
    }

    /**
     * An agent can be re-typed or inherit a non-Anthropic config from its app. Managed Agents only
     * accepts Claude model ids, so anything else falls back rather than 400ing remotely.
     */
    public static function modelFor(Agent $agent): string
    {
        $configured = $agent->config['claude_model'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $resolved = AgentProviderService::resolveModel($agent);

        return str_starts_with($resolved, 'claude') ? $resolved : self::DEFAULT_MODEL;
    }

    protected function resolveModel(): string
    {
        return self::modelFor($this->agent);
    }

    protected function resolveName(): string
    {
        $name = trim((string) $this->agent->name);

        // The API requires 1–256 chars; a blank agent name would otherwise fail validation remotely
        // rather than here, where the cause is obvious.
        return $name !== '' ? mb_substr($name, 0, 256) : 'Kanvas Agent ' . $this->agent->getId();
    }

    /**
     * Who it is, how it works, how it answers. Undefined sections are dropped rather than emitted
     * empty, so the fingerprint doesn't move when an unused field is touched.
     */
    protected function buildSystemPrompt(): ?string
    {
        $sections = array_filter([
            $this->inherited('soul'),
            $this->inherited('instructions'),
            RepoAllowListService::promptSection($this->agent),
            $this->outputFormatSection(),
        ], static fn (?string $section): bool => $section !== null);

        return $sections === [] ? null : implode("\n\n", $sections);
    }

    protected function outputFormatSection(): ?string
    {
        $format = $this->inherited('output_format');

        return $format === null ? null : "OUTPUT FORMAT:\n" . $format;
    }

    /** Agent value wins over its type's; the type still applies where the agent set nothing. */
    protected function inherited(string $field): ?string
    {
        return AgentSettingsService::trimmed($this->agent->{$field})
            ?? AgentSettingsService::trimmed($this->agent->type?->{$field});
    }

    /**
     * The prebuilt sandbox toolset plus every granted Kanvas tool, bridged as `custom` tools.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildTools(): array
    {
        $tools = [
            ['type' => self::AGENT_TOOLSET],
            ...$this->bridge()->definitions(),
        ];

        if ($this->hasGithubMcp()) {
            $tools[] = [
                'type' => 'mcp_toolset',
                'mcp_server_name' => self::GITHUB_MCP_NAME,
                // MCP toolsets default to `always_ask`, which parks the session on a
                // `requires_action` stop waiting for a `user.tool_confirmation` we have no UI to
                // collect. Auto-allowing is safe here because the real boundary is the PAT's own
                // scope plus the repo allow-list — an approval prompt nobody can see adds nothing.
                'default_config' => ['permission_policy' => ['type' => 'always_allow']],
            ];
        }

        return $tools;
    }

    /**
     * Declared without credentials on purpose — the agent object is a reusable definition, and the
     * auth for it lives in the vault attached at session create.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildMcpServers(): array
    {
        if (! $this->hasGithubMcp()) {
            return [];
        }

        return [
            ['type' => 'url', 'name' => self::GITHUB_MCP_NAME, 'url' => self::GITHUB_MCP_URL],
        ];
    }

    /**
     * Both halves are required: a vault with no repos has nothing to open a PR against, and repos
     * with no vault can still be cloned and pushed — just not turned into a pull request.
     */
    protected function hasGithubMcp(): bool
    {
        return AgentSettingsService::vaultId($this->agent) !== null
            && RepoAllowListService::sessionResources($this->agent) !== [];
    }

    protected function bridge(): CustomToolBridgeService
    {
        return $this->bridge ??= new CustomToolBridgeService($this->agent);
    }
}
