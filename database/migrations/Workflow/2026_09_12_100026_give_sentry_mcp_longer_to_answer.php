<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sentry's `search_events` takes a natural-language question, translates it server-side and then scans
 * events, which regularly runs past twenty seconds — the transport gave up at exactly 20s and killed the
 * agent's turn.
 *
 * Sixty seconds matches the other servers that do real work per call (Browserbase and Playwright drive a
 * browser). The ceiling still exists: a server that never answers must not hold a worker forever.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->setTimeout(60000);
    }

    public function down(): void
    {
        $this->setTimeout(20000);
    }

    private function setTimeout(int $milliseconds): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'sentry_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $metadata['timeout_ms'] = $milliseconds;

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
