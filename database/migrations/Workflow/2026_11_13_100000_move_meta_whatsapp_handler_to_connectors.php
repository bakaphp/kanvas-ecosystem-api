<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const string OLD = 'Kanvas\\Communication\\Channels\\WhatsApp\\Providers\\Meta\\Handlers\\MetaWhatsAppHandler';
    private const string CURRENT = 'Kanvas\\Connectors\\Meta\\Handlers\\MetaWhatsAppHandler';

    public function up(): void
    {
        DB::connection('workflow')
            ->table('integrations')
            ->where('handler', self::OLD)
            ->update(['handler' => self::CURRENT]);
    }

    public function down(): void
    {
        DB::connection('workflow')
            ->table('integrations')
            ->where('handler', self::CURRENT)
            ->update(['handler' => self::OLD]);
    }
};
