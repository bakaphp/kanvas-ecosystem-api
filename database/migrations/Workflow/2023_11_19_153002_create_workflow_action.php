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
        Schema::create('rules_workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actions_id')->nullable();
            $table->unsignedBigInteger('system_modules_id')->nullable();
            $table->timestamps();
            $table->integer('is_deleted')->default(0);

            $table->index('actions_id');
            $table->index('system_modules_id');
            $table->index('created_at');
            $table->index('is_deleted');


            $table->foreign('actions_id')->references('id')->on('actions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules_workflow_actions');
    }
};
