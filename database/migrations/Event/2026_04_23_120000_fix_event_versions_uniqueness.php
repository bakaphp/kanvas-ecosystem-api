<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::connection('event')->statement(
            'UPDATE event_versions SET version = 0 WHERE version IS NULL OR version = ""'
        );

        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->change();
        });

        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->dropUnique('event_version_slug_unique');
            $table->unique(
                ['apps_id', 'companies_id', 'event_id', 'version'],
                'event_version_event_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->dropUnique('event_version_event_version_unique');
            $table->unique(['slug', 'apps_id', 'companies_id'], 'event_version_slug_unique');
        });

        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->string('version', 255)->change();
        });
    }
};
