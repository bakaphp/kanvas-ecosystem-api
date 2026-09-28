<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('social')->create('twilio_message_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->integer('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('message_id')->nullable()->index();
            $table->bigInteger('lead_id')->nullable()->index();
            $table->string('message_sid', 64)->nullable()->unique();
            $table->string('account_sid', 64)->nullable()->index();
            $table->string('messaging_service_sid', 64)->nullable();
            $table->string('from_number', 64)->nullable();
            $table->string('to_number', 64)->nullable()->index();
            $table->string('current_status', 32)->index();
            $table->integer('last_error_code')->nullable()->index();
            $table->text('last_error_message')->nullable();
            $table->string('classification', 64)->nullable()->index();
            $table->string('remediation_action', 64)->nullable();
            $table->unsignedSmallInteger('retry_number')->default(0);
            $table->bigInteger('parent_attempt_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('terminal_at')->nullable()->index();
            $table->timestamp('reconciled_at')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(
                ['apps_id', 'companies_id', 'current_status', 'is_deleted'],
                'ix_twilio_attempt_status',
            );
        });

        Schema::connection('social')->create('twilio_message_delivery_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->integer('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('attempt_id')->index();
            $table->string('event_key', 64)->unique();
            $table->string('source', 32)->index();
            $table->string('status', 32)->nullable()->index();
            $table->integer('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->string('classification', 64)->nullable();
            $table->string('remediation_action', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_result')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(['attempt_id', 'received_at'], 'ix_twilio_event_attempt_time');
        });
    }

    public function down(): void
    {
        Schema::connection('social')->dropIfExists('twilio_message_delivery_events');
        Schema::connection('social')->dropIfExists('twilio_message_attempts');
    }
};
