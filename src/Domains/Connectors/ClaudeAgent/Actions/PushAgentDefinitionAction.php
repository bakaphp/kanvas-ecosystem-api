<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSettingsService;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Create-or-version the remote agent object for a Kanvas agent.
 *
 * This runs on the chat path, so the common case must cost nothing: an unchanged spec fingerprint
 * returns the stored id and makes **no HTTP call**. Creating per turn would orphan an agent object
 * per message and defeat versioning entirely.
 */
class PushAgentDefinitionAction
{
    use ResolvesClaudeClient;

    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Client $client = null,
    ) {
    }

    /**
     * @return array{id: string, version: int, pushed: bool} `pushed` is false when nothing changed.
     */
    public function execute(): array
    {
        // Before the spec, not after: the vault is what turns the GitHub MCP toolset on, and the
        // spec reads its id to decide whether to declare the server.
        new EnsureGithubVaultAction($this->agent, $this->client)->execute();

        $spec = new AgentSpecBuilderService($this->agent)->build();
        $fingerprint = $spec->fingerprint();

        $storedId = $this->stored(CustomFieldEnum::CLAUDE_AGENT_ID);

        if ($storedId !== null && $this->stored(CustomFieldEnum::CLAUDE_AGENT_FINGERPRINT) === $fingerprint) {
            return [
                'id' => $storedId,
                'version' => (int) $this->stored(CustomFieldEnum::CLAUDE_AGENT_VERSION),
                'pushed' => false,
            ];
        }

        $client = $this->claudeClient($this->agent->app, $this->agent->company);

        $response = $storedId === null
            ? $client->createAgent($spec->toPayload())
            : $client->updateAgent($storedId, $spec->toPayload());

        return $this->persist($response, $fingerprint);
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, version: int, pushed: bool}
     */
    protected function persist(array $response, string $fingerprint): array
    {
        $id = (string) ($response['id'] ?? '');

        if ($id === '') {
            throw new ClaudeAgentApiException('Claude Managed Agents returned an agent without an id.', 0);
        }

        $version = (int) ($response['version'] ?? 1);

        $this->agent->set(CustomFieldEnum::CLAUDE_AGENT_ID->value, $id);
        $this->agent->set(CustomFieldEnum::CLAUDE_AGENT_VERSION->value, $version);
        // Written last so a failure mid-write leaves the fingerprint stale and the next run re-pushes,
        // rather than recording "in sync" against a half-written state.
        $this->agent->set(CustomFieldEnum::CLAUDE_AGENT_FINGERPRINT->value, $fingerprint);

        return ['id' => $id, 'version' => $version, 'pushed' => true];
    }

    protected function stored(CustomFieldEnum $field): ?string
    {
        return AgentSettingsService::get($this->agent, $field);
    }
}
