<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('companies_id')->unsigned()->index();
            $table->bigInteger('users_id')->unsigned()->index();
            $table->bigInteger('event_version_id')->unsigned()->index();
            $table->string('notification_type');
            $table->dateTime('send_at')->index();
            $table->string('status')->default('pending')->index();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['notification_type', 'status', 'send_at'], 'event_reminders_due_lookup_index');
            $table->unique(['event_version_id', 'notification_type'], 'event_reminders_event_version_type_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('event')->dropIfExists('event_reminders');
    }
};
