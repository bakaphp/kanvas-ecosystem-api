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
        Schema::create('rules_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rules_id')->nullable();
            $table->unsignedBigInteger('rules_workflow_actions_id')->nullable();
            $table->decimal('weight', 3, 2)->default(0.00);
            $table->timestamps();
            $table->integer('is_deleted')->default(0);

            $table->index('rules_id');
            $table->index('rules_workflow_actions_id');
            $table->index('created_at');
            $table->index('is_deleted');

            $table->foreign('rules_id')->references('id')->on('rules')->onDelete('set null');
            $table->foreign('rules_workflow_actions_id')->references('id')->on('rules_workflow_actions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules_actions');
    }
};
