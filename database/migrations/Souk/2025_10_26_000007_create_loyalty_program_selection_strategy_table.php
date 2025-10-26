<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('commerce')->create('loyalty_program_selection_strategy', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->string('name');
            $table->text('description')->nullable();
            
            // Strategy settings
            $table->enum('strategy_type', ['first_match', 'highest_priority', 'user_choice', 'all_matching'])->default('first_match');
            
            // Enable/disable
            $table->boolean('is_active')->default(true);
            
            // Metadata
            $table->json('meta')->nullable();
            
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->unique(['apps_id', 'strategy_type']);
            $table->index('apps_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_program_selection_strategy');
    }
};