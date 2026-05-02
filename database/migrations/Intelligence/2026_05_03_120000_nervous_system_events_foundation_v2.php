<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->char('causation_id', 36)->nullable()->after('correlation_id');
            $table->unsignedSmallInteger('payload_schema_version')->default(1)->after('payload');

            $table->index(['causation_id'], 'causation_id_idx');
        });

        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('archived_occurred');
            $table->dropColumn('is_archived');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->boolean('is_archived')->default(0)->after('indexed_at');
            $table->index(['is_archived', 'occurred_at'], 'archived_occurred');
        });

        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('causation_id_idx');
            $table->dropColumn(['causation_id', 'payload_schema_version']);
        });
    }
};
