<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class () extends Migration {
    public function up(): void
    {
        // Only byte-for-byte stock copies are ours to replace, including app and company copies.
        DB::table('email_templates')
            ->where('name', 'scribe-sow')
            ->where('engine', 'twig')
            ->where('is_deleted', 0)
            ->whereRaw('SHA2(template, 256) = ?', ['de18fbdcfabf2627217e3e127091b4797795e416396ee6afd1935fd89e47412e'])
            ->update([
                'template' => File::get(resource_path('views/pdf/scribe-sow.twig')),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep the corrected presentation; reverting must not overwrite later template edits.
    }
};
