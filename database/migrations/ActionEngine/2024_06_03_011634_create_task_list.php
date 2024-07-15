<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_task_list', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('users_id')->index();
            $table->string('name');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0)->index();
        });

        Schema::create('company_task_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_list_id')->constrained('company_task_list')->onDelete('cascade');
            $table->string('name');
            $table->bigInteger('companies_action_id')->index();
            $table->enum('status', ['pending', 'in_progress', 'completed','no_applicable'])->default('pending')->comment('pending, in_progress, completed')->index();
            $table->json('config')->nullable();
            $table->decimal('weight', 8, 2)->default(0)->index();
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_task_list');
        Schema::dropIfExists('company_task_list_items');
    }
};
