<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who was asked, at which step, and what they said — the "approved by who" answer, and the reason the
 * whole chain is written at request time rather than resolved at decision time: approver lists change,
 * and an audit saying "approved by X" has to also say who else was asked, and who was deliberately not.
 *
 * email is denormalized on purpose. Slack matches approvers by their profile email, and a user can
 * change theirs after the fact; the request has to keep the address it actually notified.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('approval_request_approvers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_requests_id');
            $table->unsignedInteger('users_id');
            $table->string('email', 255)->nullable();

            $table->unsignedInteger('step')->default(1);
            $table->string('decision', 16)->default('waiting');
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedInteger('delegated_to_users_id')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->string('notification_channel', 32)->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['approval_requests_id', 'step', 'decision'], 'ara_request_step_idx');
            $table->index(['users_id', 'decision'], 'ara_user_decision_idx');
            $table->unique(['approval_requests_id', 'users_id', 'step'], 'ara_request_user_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_request_approvers');
    }
};
