<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('app_user_mailings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->integer('apps_id')->index();
            $table->integer('companies_id')->index();
            $table->integer('users_id')->nullable();
            $table->integer('agents_id')->nullable();
            $table->string('audience', 30)->default('active_users');
            $table->string('template_name', 255);
            $table->string('subject', 255)->nullable();
            $table->json('criteria')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('confirmed_total')->nullable();
            $table->unsignedInteger('total_eligible')->default(0);
            $table->unsignedInteger('total_excluded')->default(0);
            $table->json('excluded_breakdown')->nullable();
            $table->json('recipient_ids')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('failed_reason')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index(['apps_id', 'companies_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_mailings');
    }
};
