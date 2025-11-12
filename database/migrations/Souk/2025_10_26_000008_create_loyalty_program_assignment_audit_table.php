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
        Schema::connection('commerce')->create('loyalty_program_assignment_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('orders_id')->nullable();
            $table->unsignedBigInteger('loyalty_programs_id');

            // Why this program was selected
            $table->enum('selection_reason', [
                'user_selection',
                'membership_existing',
                'first_purchase_rule',
                'purchase_count_rule',
                'spending_amount_rule',
                'tier_status_rule',
                'user_segment_rule',
                'default_program',
                'referral_source',
                'custom_rule',
            ]);

            // Conditions that matched
            $table->json('matched_conditions')->nullable();

            // Alternative programs that could have matched
            $table->json('alternative_programs')->nullable();

            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->foreign('orders_id')->references('id')->on('orders')->onDelete('set null');
            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');

            $table->index('users_id');
            $table->index('orders_id');
            $table->index('loyalty_programs_id');
            $table->index('selection_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_program_assignment_audit');
    }
};
