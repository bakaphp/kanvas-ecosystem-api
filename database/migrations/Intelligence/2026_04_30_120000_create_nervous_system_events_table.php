<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('source_domain', 64);
            $table->string('source_entity_type', 191)->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->string('event_type', 64);
            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('status', 16)->default('info');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->timestamp('occurred_at', 3);
            $table->timestamp('indexed_at', 3)->default(DB::raw('CURRENT_TIMESTAMP(3)'));
            $table->boolean('is_archived')->default(0);

            $table->index(['apps_id', 'companies_id', 'occurred_at'], 'apps_company_occurred');
            $table->index(['source_domain', 'event_type', 'occurred_at'], 'domain_type_occurred');
            $table->index(['source_entity_type', 'source_entity_id', 'occurred_at'], 'entity_occurred');
            $table->index(['apps_id', 'companies_id', 'status', 'occurred_at'], 'apps_company_status_occurred');
            $table->index(['correlation_id'], 'correlation_id_idx');
            $table->index(['is_archived', 'occurred_at'], 'archived_occurred');
            $table->index('event_type', 'event_type_idx');
        });

        Schema::connection('intelligence')->create('nervous_system_event_archives', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id')->nullable();
            $table->unsignedBigInteger('companies_id')->nullable();
            $table->date('window_starts_at');
            $table->date('window_ends_at');
            $table->string('s3_disk', 64);
            $table->string('s3_path', 500);
            $table->unsignedBigInteger('event_count');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('archived_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->index(['window_starts_at'], 'window_starts_idx');
            $table->index(['apps_id', 'companies_id', 'window_starts_at'], 'apps_company_window_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_event_archives');
        Schema::connection('intelligence')->dropIfExists('nervous_system_events');
    }
};
