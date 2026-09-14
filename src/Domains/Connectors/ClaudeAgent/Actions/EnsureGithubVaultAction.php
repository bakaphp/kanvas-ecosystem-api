<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSettingsService;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Connectors\ClaudeAgent\Services\RepoAllowListService;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Give the agent the GitHub API, using the PAT it already has.
 *
 * A mounted repo buys filesystem + git and nothing else: the git proxy injects the token *after* the
 * request leaves the container, so `git push` works while `api.github.com` answers 401. Everything
 * that is an API call rather than a git operation — opening a PR, commenting, reading a review —
 * needs GitHub's remote MCP server, and that server needs a credential.
 *
 * The credential is a vault entry, not an env var and not an OAuth app: `static_bearer` takes the
 * same PAT the allow-list already carries. Anthropic attaches it to the MCP connection at session
 * runtime, so the token still never enters the sandbox — the same property the git proxy has.
 *
 * Runs on the chat path, so the settled case costs nothing: a stored id whose fingerprint matches
 * the current token returns without a single HTTP call.
 */
class EnsureGithubVaultAction
{
    use ResolvesClaudeClient;

    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Client $client = null,
    ) {
    }

    /**
     * @return string|null The vault id, or null when this agent has no repository work to do.
     */
    public function execute(): ?string
    {
        $token = RepoAllowListService::token($this->agent);

        if ($token === null || RepoAllowListService::forAgent($this->agent) === []) {
            return null;
        }

        $fingerprint = hash('sha256', $token);
        $vaultId = AgentSettingsService::vaultId($this->agent);

        if ($vaultId !== null) {
            if (AgentSettingsService::get($this->agent, CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT) !== $fingerprint) {
                $this->rotate($vaultId, $token, $fingerprint);
            }

            return $vaultId;
        }

        $client = $this->claudeClient($this->agent->app, $this->agent->company);

        $vault = $client->createVault([
            'display_name' => $this->vaultName(),
            'metadata' => [
                'kanvas_agent_id' => (string) $this->agent->getId(),
                'kanvas_app_id' => (string) $this->agent->app->getId(),
                'kanvas_company_id' => (string) $this->agent->company->getId(),
            ],
        ]);

        $vaultId = (string) ($vault['id'] ?? '');

        if ($vaultId === '') {
            throw new ClaudeAgentApiException('Claude Managed Agents returned a vault without an id.', 0);
        }

        // Stored before the credential call: a vault we created but failed to populate must still be
        // findable, or the next run leaks a second one for the same agent.
        $this->agent->set(CustomFieldEnum::CLAUDE_VAULT_ID->value, $vaultId);

        $client->createVaultCredential($vaultId, $this->credentialPayload($token));

        $this->agent->set(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value, $fingerprint);

        return $vaultId;
    }

    /**
     * The PAT changed. `mcp_server_url` is immutable, so the existing credential is updated in place
     * rather than replaced — and a running session picks the new secret up without a restart.
     */
    protected function rotate(string $vaultId, string $token, string $fingerprint): void
    {
        $client = $this->claudeClient($this->agent->app, $this->agent->company);
        $credentialId = $this->findCredentialId($client, $vaultId);

        $credentialId === null
            ? $client->createVaultCredential($vaultId, $this->credentialPayload($token))
            : $client->updateVaultCredential($vaultId, $credentialId, $this->credentialPayload($token));

        $this->agent->set(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value, $fingerprint);
    }

    protected function findCredentialId(Client $client, string $vaultId): ?string
    {
        foreach ($client->listVaultCredentials($vaultId)['data'] ?? [] as $credential) {
            if (! is_array($credential)) {
                continue;
            }

            $url = $credential['auth']['mcp_server_url'] ?? null;

            if ($url === AgentSpecBuilderService::GITHUB_MCP_URL) {
                return (string) $credential['id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialPayload(string $token): array
    {
        return [
            'display_name' => 'GitHub PAT',
            'auth' => [
                'type' => 'static_bearer',
                'mcp_server_url' => AgentSpecBuilderService::GITHUB_MCP_URL,
                'token' => $token,
            ],
        ];
    }

    protected function vaultName(): string
    {
        return sprintf(
            'kanvas-agent-%d-github (app %d, company %d)',
            $this->agent->getId(),
            $this->agent->app->getId(),
            $this->agent->company->getId(),
        );
    }
}
