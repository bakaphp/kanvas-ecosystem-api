<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Browserbase has one fixed address, so an admin should paste its API key and nothing else.
 *
 * It reads that key from the query string rather than an Authorization header, which is why the row now
 * carries `auth_query_param`: the transport appends `?browserbaseApiKey=<key>` at send time, so the key
 * is stored as an ordinary credential and never as part of an address. That replaces the earlier shape,
 * where the admin pasted the whole URL with the key inside it.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->write([
            'url' => 'https://mcp.browserbase.com/mcp',
            'auth_methods' => ['bearer'],
            'auth_query_param' => 'browserbaseApiKey',
            'url_per_connection' => false,
        ]);
    }

    public function down(): void
    {
        $this->write([
            'url' => null,
            'auth_methods' => ['none', 'bearer'],
            'auth_query_param' => null,
            'url_per_connection' => true,
        ]);
    }

    /**
     * @param array{url: string|null, auth_methods: list<string>, auth_query_param: string|null, url_per_connection: bool} $changes
     */
    private function write(array $changes): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'browserbase_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($metadata[$key]);

                continue;
            }

            $metadata[$key] = $value;
        }

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
