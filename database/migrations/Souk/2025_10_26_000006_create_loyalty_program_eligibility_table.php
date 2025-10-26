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
        Schema::connection('commerce')->create('loyalty_program_eligibility', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->string('name');
            $table->text('description')->nullable();
            
            // Eligibility conditions (all must be true)
            $table->boolean('requires_existing_membership')->default(false);
            $table->integer('min_purchase_count')->nullable();
            $table->integer('max_purchase_count')->nullable();
            $table->decimal('min_spending_amount', 12, 2)->nullable();
            $table->decimal('max_spending_amount', 12, 2)->nullable();
            $table->json('required_tier_ids')->nullable();
            $table->json('allowed_user_segments')->nullable();
            $table->json('excluded_user_ids')->nullable();
            
            // Assignment settings
            $table->boolean('auto_enroll')->default(true);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->index('apps_id');
            $table->index('loyalty_programs_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_program_eligibility');
    }
};