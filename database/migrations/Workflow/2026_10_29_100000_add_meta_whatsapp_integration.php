<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * As a Meta partner a company hands us only its number, its WABA and a business token. The app
 * secret is ours and lives in the app setting `meta_app_secret`, so the form never asks for it. The
 * row was made by hand on development, so an existing one gets this form too.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'meta_whatsapp';
    private const string HANDLER = 'Kanvas\\Communication\\Channels\\WhatsApp\\Providers\\Meta\\Handlers\\MetaWhatsAppHandler';

    public function up(): void
    {
        $config = json_encode([
            'phone_number_id' => ['type' => 'text', 'required' => true],
            'waba_id' => ['type' => 'text', 'required' => true],
            // Left empty after an embedded signup, which sends a code the handler exchanges instead.
            'access_token' => ['type' => 'password', 'required' => false],
        ]);

        $updated = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->update([
                'handler' => self::HANDLER,
                'config' => $config,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::NAME,
            'handler' => self::HANDLER,
            'config' => $config,
            'created_at' => now(),
            'is_deleted' => 0,
        ]);
    }

    /**
     * Left in place: the row may predate this migration, and companies' integrations point at it.
     */
    public function down(): void
    {
    }
};
