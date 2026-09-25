<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of a coding task on an agent harness. The NervousSystem Task stays the durable business
 * record; this is the runtime state hanging off it — and it is a table rather than Task custom fields
 * because three things need to query it: the sweeper (`status` + `heartbeat_at`), the per-tenant
 * concurrency cap, and the port allocator, which must see in-flight ports it did not hand out.
 * A task has many sessions: a retry is a new row, not a mutated one.
 *
 * No FKs — `nervous_system_tasks` is on `intelligence` but `companies`/`users` are on `ecosystem`.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('agent_task_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('agent_machine_id')->nullable();

            $table->string('harness', 32);
            $table->string('external_session_id', 191)->nullable();
            $table->string('status', 32);

            $table->string('container_name', 191)->nullable();
            $table->unsignedInteger('port')->nullable();
            // Base URL the poller talks to, computed at launch from the machine's network mode.
            $table->string('endpoint', 512)->nullable();
            $table->text('server_password')->nullable();
            $table->string('workspace_path', 512)->nullable();
            $table->string('session_data_path', 512)->nullable();
            $table->string('branch', 191)->nullable();
            // Where the branch ended up. Kept so a follow-up can find the review to answer and update
            // the same pull request instead of opening a second one for the same work.
            $table->string('pull_request_url', 512)->nullable();
            // The session this one continues, so a chain of follow-ups on one branch is walkable.
            $table->unsignedBigInteger('continues_session_id')->nullable();

            // Opaque resume cursor from the runtime, not a number: v2 paginates the conversation
            // rather than numbering it.
            $table->string('last_cursor', 512)->nullable();
            // Epoch-ms of the newest message already posted as narration; messages carry no seq of
            // their own, so this is what stops the poller re-posting the whole transcript each tick.
            $table->unsignedBigInteger('last_message_at')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('credential_source', 32)->nullable();

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('estimated_cost', 12, 6)->default(0);

            // Structured recap pulled OUT of the container before teardown, so the learning survives
            // the volume being swept and can seed the next session.
            $table->longText('handoff')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('agent_steer_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['status', 'heartbeat_at'], 'agent_task_sessions_sweeper_idx');
            $table->index(['apps_id', 'companies_id', 'status'], 'agent_task_sessions_tenant_status_idx');
            $table->index(['task_id', 'status'], 'agent_task_sessions_task_idx');
            $table->index(['agent_machine_id', 'port'], 'agent_task_sessions_machine_port_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('agent_task_sessions');
    }
};
