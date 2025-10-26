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
        Schema::connection('commerce')->create('loyalty_programs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('points_per_dollar', 5, 2)->default(1.0);
            $table->decimal('earn_multiplier', 5, 2)->default(1.0);
            $table->integer('expiration_days')->default(365);
            $table->boolean('is_active')->default(true);
            
            // Referral configuration
            $table->boolean('referral_enabled')->default(false);
            $table->enum('referral_strategy', ['single', 'multiple'])->default('single');
            $table->json('referral_config')->nullable();
            
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->index(['apps_id', 'companies_id']);
            $table->index('is_active');
            $table->index('referral_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_programs');
    }
};