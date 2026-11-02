<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google's Gmail MCP setup guide lists only `gmail.readonly` + `gmail.compose`, but the server publishes
 * tools that change mailbox state (labels, read/unread, archive). Each came back `403 insufficient_scope`
 * and took the agent's whole turn with it (Sentry, McpAuthException on agent 1335, app 65).
 *
 * `gmail.modify` is the narrowest scope that covers those writes; it does not allow permanent deletion.
 * The original scopes stay so the consent screen is a superset of what earlier connections granted.
 *
 * A scope change reaches new consents only: an agent already connected keeps its old grant until it
 * reconnects Gmail.
 */
return new class () extends Migration {
    private const string MODIFY_SCOPE = 'https://www.googleapis.com/auth/gmail.modify';

    protected $connection = 'workflow';

    public function up(): void
    {
        $this->changeScopes(fn (array $scopes): array => array_values(array_unique([...$scopes, self::MODIFY_SCOPE])));
    }

    public function down(): void
    {
        $this->changeScopes(fn (array $scopes): array => array_values(array_diff($scopes, [self::MODIFY_SCOPE])));
    }

    /**
     * @param callable(list<string>): list<string> $change
     */
    private function changeScopes(callable $change): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'google_gmail_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $scopes = $metadata['oauth']['scopes'] ?? [];
        $metadata['oauth']['scopes'] = $change(is_array($scopes) ? $scopes : []);

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
