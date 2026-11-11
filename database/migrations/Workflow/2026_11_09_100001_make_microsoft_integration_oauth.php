<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The `microsoft` row the Outlook activities look up was added by hand where it exists, as a `key`
 * integration whose handler class was never written, so saving it always failed. It becomes the
 * company's OAuth connection (Outlook and its own OneDrive) in place: same id, so integration_companies
 * rows already pointing at it keep working.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $values = [
            'type' => 'oauth',
            'handler' => 'Kanvas\\Connectors\\Microsoft\\Handlers\\MicrosoftHandler',
            'config' => json_encode([
                'root_folder' => ['type' => 'text', 'required' => false],
            ]),
            'updated_at' => now(),
        ];

        $existing = DB::connection('workflow')->table('integrations')
            ->where('name', 'microsoft')
            ->where('apps_id', 0)
            ->first();

        if ($existing !== null) {
            DB::connection('workflow')->table('integrations')->where('id', $existing->id)->update($values);

            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            ...$values,
            'uuid' => (string) Str::uuid(),
            'name' => 'microsoft',
            'apps_id' => 0,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * Nothing to restore: the previous handler never existed, so the old row could not be saved anyway.
     */
    public function down(): void
    {
    }
};
