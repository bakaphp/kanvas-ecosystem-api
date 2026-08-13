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
        Schema::connection('intelligence')->create('nervous_system_scheduled_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('agent_id')->nullable();

            $table->string('action_type', 32);
            $table->string('status', 32)->default('pending');

            $table->timestamp('run_at');
            $table->string('timezone', 64);

            $table->string('recurrence_cron', 128)->nullable();
            $table->timestamp('recurrence_ends_at')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);

            $table->json('payload')->nullable();
            $table->string('channel', 64)->nullable();
            $table->char('session_uuid', 36)->nullable();
            $table->string('source_entity_type', 191)->nullable();
            $table->string('source_entity_id', 191)->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            // The due-query: the sweep scans (status, run_at) only. Same shape for one-off and
            // recurring rows — recurring just re-arms run_at, so it stays a plain pending lookup.
            // Global sweep (cross-tenant by design): status=pending + run_at range. Not tenant-prefixed
            // on purpose — the sweep serves every app at once.
            $table->index(['status', 'run_at'], 'sa_due');
            // Tenant + owner listing, ordered by fire time (list_scheduled_actions, and any per-tenant view).
            $table->index(['apps_id', 'companies_id', 'users_id', 'run_at'], 'sa_owner');
            $table->index(['agent_id', 'status'], 'sa_agent');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_scheduled_actions');
    }
};
