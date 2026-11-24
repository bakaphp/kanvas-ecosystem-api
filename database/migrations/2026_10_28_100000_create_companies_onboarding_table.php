<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('companies_onboarding', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->comment('who started it');
            $table->string('status', 32);
            $table->string('current_step', 32)->nullable();
            $table->json('data')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('skipped_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->unique(['apps_id', 'companies_id']);
            $table->index(['apps_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies_onboarding');
    }
};
