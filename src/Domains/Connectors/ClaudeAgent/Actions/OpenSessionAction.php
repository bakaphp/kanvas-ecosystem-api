<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSettingsService;
use Kanvas\Connectors\ClaudeAgent\Services\RepoAllowListService;
use Kanvas\Connectors\ClaudeAgent\Traits\ReportsAndContinues;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;

/**
 * Resolve the remote session backing a Kanvas conversation, creating it on first use.
 *
 * The mapping rides `Session->content` (already a JSON-cast column), which is what keeps phase 1
 * migration-free. A turn with no Kanvas session still works — it just opens an unmapped remote
 * session that nothing will resume.
 */
class OpenSessionAction
{
    use ReportsAndContinues;
    use ResolvesClaudeClient;

    public const string SESSION_ID_KEY = 'claude_session_id';
    public const string EVENT_CURSOR_KEY = 'claude_event_cursor';
    public const string RESOURCE_FINGERPRINT_KEY = 'claude_resource_fingerprint';

    /**
     * @param list<string> $repoSlugs Restrict mounted repos to these; empty means the whole allow-list.
     * @param list<array<string, mixed>> $initialEvents Starts the agent loop in the same call.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $environmentId,
        protected readonly string $remoteAgentId,
        protected readonly int $remoteAgentVersion = 0,
        protected readonly ?Client $client = null,
        protected readonly array $repoSlugs = [],
        protected readonly array $initialEvents = [],
        protected readonly ?string $title = null,
    ) {
    }

    public function execute(): string
    {
        $resources = RepoAllowListService::sessionResources($this->agent, $this->repoSlugs);
        $vaultId = AgentSettingsService::vaultId($this->agent);
        $fingerprint = self::resourceFingerprint($resources, $vaultId, $this->remoteAgentVersion);

        $existing = self::storedSessionId($this->session);

        // Resources, vaults and the toolset all freeze at session creation, so reuse is only safe
        // while the config that shaped the session still matches. Otherwise a session opened before
        // repos were granted stays repo-less forever, and one opened before a permission-policy
        // change keeps parking on `requires_action` however often we re-push the agent.
        if ($existing !== null && self::storedFingerprint($this->session) === $fingerprint) {
            return $existing;
        }

        $payload = array_filter([
            'agent' => $this->remoteAgentId,
            'environment_id' => $this->environmentId,
            'title' => trim((string) ($this->title ?? $this->agent->name)),
            'budget' => $this->budget(),
            'resources' => $resources,
            // MCP credentials live in a vault; the sandbox never sees them. Create-only — the API
            // rejects vault_ids on update, so one not attached here never can be.
            'vault_ids' => array_values(array_filter([$vaultId])),
            'initial_events' => $this->initialEvents,
        ]);

        $client = $this->claudeClient($this->agent->app, $this->agent->company);

        if ($existing !== null) {
            // The session we are replacing would otherwise live forever holding its sandbox —
            // nothing expires it and we are about to forget its id.
            $this->bestEffort(fn () => $client->archiveSession($existing));
        }

        $sessionId = (string) ($client->createSession($payload)['id'] ?? '');

        if ($sessionId === '') {
            throw new ClaudeAgentApiException('Claude Managed Agents returned a session without an id.', 0);
        }

        // Clearing the cursor matters: it is only meaningful against the session that produced it,
        // and a stale one makes the drain skip forward hunting an event id that will never appear.
        self::writeContent($this->session, [
            self::SESSION_ID_KEY => $sessionId,
            self::RESOURCE_FINGERPRINT_KEY => $fingerprint,
            self::EVENT_CURSOR_KEY => null,
        ]);

        return $sessionId;
    }

    /**
     * Create-only on the API — a budget can never be added to a session later, so it is decided here
     * or not at all. Amount is minor units as an integer string; a decimal form is rejected and a
     * float would risk rounding a spend cap.
     *
     * @return array<string, mixed>|null
     */
    protected function budget(): ?array
    {
        $cents = (int) ($this->agent->get(CustomFieldEnum::CLAUDE_SESSION_BUDGET_CENTS->value) ?? 0);

        if ($cents <= 0) {
            return null;
        }

        return [
            'type' => 'limit',
            'max_list_cost' => ['amount' => (string) $cents, 'currency' => 'USD'],
        ];
    }

    public static function storedSessionId(?Session $session): ?string
    {
        return self::readContent($session, self::SESSION_ID_KEY);
    }

    public static function storedCursor(?Session $session): ?string
    {
        return self::readContent($session, self::EVENT_CURSOR_KEY);
    }

    protected static function storedFingerprint(?Session $session): ?string
    {
        return self::readContent($session, self::RESOURCE_FINGERPRINT_KEY);
    }

    /**
     * Covers everything fixed at creation time. The token is hashed with the rest rather than
     * stored, so rotating it also re-opens the session without ever writing the secret to the row.
     * The agent version stands in for the whole spec: a new version means new tools or new
     * permission policies, neither of which a running session can pick up.
     *
     * @param list<array<string, mixed>> $resources
     */
    protected static function resourceFingerprint(array $resources, ?string $vaultId, int $agentVersion): string
    {
        return hash('sha256', (string) json_encode([$resources, $vaultId, $agentVersion]));
    }

    public static function storeCursor(?Session $session, ?string $cursor): void
    {
        if ($cursor !== null && $cursor !== '') {
            self::writeContent($session, [self::EVENT_CURSOR_KEY => $cursor]);
        }
    }

    protected static function readContent(?Session $session, string $key): ?string
    {
        $content = $session?->content;
        $value = is_array($content) ? trim((string) ($content[$key] ?? '')) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, string|null> $changes A null value removes the key.
     */
    protected static function writeContent(?Session $session, array $changes): void
    {
        if (! $session instanceof Session) {
            return;
        }

        $content = is_array($session->content) ? $session->content : [];

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($content[$key]);

                continue;
            }

            $content[$key] = $value;
        }

        $session->content = $content;
        $session->saveQuietly();
    }
}
