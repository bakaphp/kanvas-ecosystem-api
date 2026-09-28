<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Browserbase also accepts the key method, so the existing admin UI can connect it today.
 *
 * The server itself still authenticates by the key in its address (`none` is the honest description, and
 * stays first). But the UI renders only `bearer` and `oauth`, and shows anything else as "an
 * authentication method the admin doesn't support yet" — offering `bearer` as well means an admin pastes
 * the URL AND the key, and the extra Authorization header Browserbase ignores costs nothing. Drop
 * `bearer` again once the UI handles a server that asks for an address alone.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->setAuthMethods(['none', 'bearer']);
    }

    public function down(): void
    {
        $this->setAuthMethods(['none']);
    }

    /**
     * @param list<string> $methods
     */
    private function setAuthMethods(array $methods): void
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

        $metadata['auth_methods'] = $methods;

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
