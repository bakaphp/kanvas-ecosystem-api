<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable accounting audit of finance-side approvals (per plan §4.4 + §5).
 *
 * Cut B usage: expense approval only — when an Expense is submitted for approval, a row lands here. When
 * approved/rejected, the row transitions + the linked Expense is updated.
 *
 * Phase 2 expansion: agent-write-action gating (CFO agent proposes credit notes, payment reminders, etc.
 * — each goes through this queue). The `nervous_system_plan_id` / `nervous_system_task_id` columns let the
 * agent's NS workflow link back to the durable accounting audit row.
 *
 * @see plan §4.4 — NervousSystem → CFO agent + approval flow
 * @see plan §5 accounting.approval_queue
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('approval_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            // Who requested the approval
            $table->unsignedBigInteger('requested_by_agent_id')->nullable();  // null when human-originated
            $table->unsignedInteger('requested_by_users_id')->nullable();     // null when agent-originated

            // What's being approved
            $table->string('action_type', 64);                                // e.g. 'approve_expense', 'issue_credit_note'
            $table->string('target_type', 64);                                // polymorphic — what entity is the target
            $table->unsignedBigInteger('target_id');
            $table->json('payload')->nullable();                              // the diff/details proposed by the agent

            // Approval lifecycle
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->unsignedInteger('approved_by_users_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Link back to NS workflow surface (so the agent's Task can auto-resolve when this is closed)
            $table->unsignedBigInteger('nervous_system_plan_id')->nullable();
            $table->unsignedBigInteger('nervous_system_task_id')->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status'], 'aq_app_company_status_idx');
            $table->index(['apps_id', 'companies_id', 'target_type', 'target_id'], 'aq_app_company_target_idx');
            $table->index(['nervous_system_task_id'], 'aq_ns_task_idx');
            $table->index(['uuid'], 'aq_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('approval_queue');
    }
};
