<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google's Calendar MCP setup guide lists only three read-only scopes, but the server publishes four tools
 * that write (`create_event`, `update_event`, `delete_event`, `respond_to_event`). Every one of those came
 * back `403 insufficient_scope`, and the failure took the agent's whole turn with it (Sentry
 * KANVAS-ECOSYSTEM-6EB, `create_event`).
 *
 * The narrowest scope Google's own challenge accepts for those writes is `calendar.events`: events only,
 * not calendar settings or sharing. Full `calendar` is accepted too, but no tool needs it. The read scopes
 * stay, because `list_calendars` and `suggest_time` rely on them.
 *
 * A scope change reaches new consents only: an agent already connected keeps its read-only grant until it
 * reconnects Calendar.
 */
return new class () extends Migration {
    private const string WRITE_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    protected $connection = 'workflow';

    public function up(): void
    {
        $this->changeScopes(fn (array $scopes): array => array_values(array_unique([...$scopes, self::WRITE_SCOPE])));
    }

    public function down(): void
    {
        $this->changeScopes(fn (array $scopes): array => array_values(array_diff($scopes, [self::WRITE_SCOPE])));
    }

    /**
     * @param callable(list<string>): list<string> $change
     */
    private function changeScopes(callable $change): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'google_calendar_mcp')
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
