<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An MCP server now lists every way it can be connected (`auth_methods`) and the admin picks one per
 * agent, instead of the row fixing a single `auth`. Existing rows keep the method they had.
 *
 * Atlassian gains OAuth: its MCP server publishes RFC 9728 metadata naming an authorization server
 * with dynamic registration, public (secretless) clients and S256 — exactly what McpOAuthProvider
 * does. Scopes are listed because the authorization server grants nothing unasked; `offline_access`
 * is what yields a refresh token. Checked against the live metadata on 2026-09-11, not yet against a
 * live consent.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array ATLASSIAN_SCOPES = [
        'read:me',
        'offline_access',
        'read:jira:agent-interface',
        'write:jira:agent-interface',
        'search:jira:agent-interface',
        'read:confluence:agent-interface',
        'write:confluence:agent-interface',
        'search:confluence:agent-interface',
        'search:rovo:agent-interface',
    ];

    public function up(): void
    {
        foreach ($this->mcpRows() as $row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];

            if (! isset($metadata['auth_methods'])) {
                $metadata['auth_methods'] = [$metadata['auth'] ?? 'bearer'];
            }

            unset($metadata['auth'], $metadata['kind']);

            if ($row->name === 'atlassian_mcp') {
                $metadata['auth_methods'] = ['bearer', 'oauth'];
                $metadata['oauth'] = [
                    ...(is_array($metadata['oauth'] ?? null) ? $metadata['oauth'] : []),
                    'scopes' => self::ATLASSIAN_SCOPES,
                ];
            }

            $this->save($row->id, $metadata);
        }
    }

    public function down(): void
    {
        foreach ($this->mcpRows() as $row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            $metadata['auth'] = $metadata['auth_methods'][0] ?? 'bearer';
            unset($metadata['auth_methods']);

            $this->save($row->id, $metadata);
        }
    }

    private function mcpRows(): iterable
    {
        return DB::connection('workflow')->table('integrations')
            ->where('type', 'mcp')
            ->get(['id', 'name', 'metadata']);
    }

    private function save(int $id, array $metadata): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('id', $id)
            ->update(['metadata' => json_encode($metadata)]);
    }
};
