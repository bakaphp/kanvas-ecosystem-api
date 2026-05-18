<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->table('schedule_rules', function (Blueprint $table) {
            $table->string('operation_day', 16)->nullable()->after('resources_type');
        });

        DB::connection('event')->statement("
            UPDATE schedule_rules
            SET operation_day = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.operation_day'))
            WHERE JSON_EXTRACT(metadata, '$.operation_day') IS NOT NULL
        ");

        DB::connection('event')->statement("
            DELETE sr FROM schedule_rules sr
            INNER JOIN (
                SELECT id FROM (
                    SELECT id,
                        ROW_NUMBER() OVER (
                            PARTITION BY apps_id, companies_id, resources_type, resources_id, operation_day
                            ORDER BY updated_at DESC, id DESC
                        ) AS rn
                    FROM schedule_rules
                    WHERE operation_day IS NOT NULL
                ) ranked
                WHERE ranked.rn > 1
            ) dup ON sr.id = dup.id
        ");

        Schema::connection('event')->table('schedule_rules', function (Blueprint $table) {
            $table->index('operation_day');
            $table->unique(
                ['apps_id', 'companies_id', 'resources_type', 'resources_id', 'operation_day'],
                'schedule_rules_resource_day_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('schedule_rules', function (Blueprint $table) {
            $table->dropUnique('schedule_rules_resource_day_unique');
            $table->dropIndex(['operation_day']);
            $table->dropColumn('operation_day');
        });
    }
};
