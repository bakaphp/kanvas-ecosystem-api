<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request for approval of one entity, keyed polymorphically by system_modules_id + entity_id —
 * the same registry workflow rules and custom fields use, so anything registered as a system module
 * is approvable with no code change.
 *
 * entity_id is an integer, deliberately unlike filesystem_entities' char(36): every Kanvas model has
 * an int primary key, HasApprovals::pendingApproval() runs on every save of every approvable model,
 * and a string-vs-int compare loses the index on exactly that hot path.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');

            $table->unsignedInteger('system_modules_id');
            $table->unsignedBigInteger('entity_id');

            $table->string('approval_type', 64);
            $table->unsignedBigInteger('approval_policies_id')->nullable();
            $table->string('origin', 32)->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('current_step')->default(1);

            $table->unsignedInteger('requested_by_users_id')->nullable();
            $table->unsignedBigInteger('requested_by_agent_id')->nullable();

            // Frozen at request time: what the approver was actually shown.
            $table->json('payload')->nullable();

            $table->unsignedInteger('resolved_by_users_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status', 'is_deleted'], 'ar_app_company_status_idx');
            $table->index(
                ['apps_id', 'companies_id', 'system_modules_id', 'entity_id', 'status'],
                'ar_entity_idx'
            );
            $table->index(['uuid'], 'ar_uuid_idx');
            $table->index(['status', 'expires_at'], 'ar_expiry_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
