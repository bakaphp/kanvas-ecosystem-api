<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A remote MCP job an agent started and is waiting on (Browser Use's `run_session` runs for minutes).
 * The agent's turn ends when the job starts; this row is what lets a background poll resume the agent
 * in the same conversation once the vendor reports it done.
 *
 * No FKs — `integrations` is on `workflow`, `companies` on `ecosystem`.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_mcp_async_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('agents_id');
            $table->unsignedBigInteger('integrations_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->string('session_uuid', 191);
            $table->string('start_tool', 128);
            $table->string('external_id', 191);
            $table->string('status', 32);
            $table->string('external_status', 64)->nullable();
            $table->text('live_url')->nullable();
            $table->text('live_url_posted')->nullable();
            $table->longText('result')->nullable();
            $table->json('artifacts')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('poll_count')->default(0);
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'status'], 'mcp_async_jobs_tenant_status_idx');
            $table->index(['agents_id', 'status'], 'mcp_async_jobs_agent_status_idx');
            $table->index(['integrations_id', 'external_id'], 'mcp_async_jobs_integration_external_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_mcp_async_jobs');
    }
};
