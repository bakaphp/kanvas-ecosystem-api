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
        Schema::connection('commerce')->create('loyalty_offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->default(0)->index();
            $table->string('name');
            $table->string('headline')->nullable();
            $table->text('description')->nullable();
            $table->enum('offer_type', ['discount', 'points', 'exclusive', 'free_shipping'])->default('points');
            $table->enum('trigger_type', [
                'first_purchase',
                'tier_upgrade',
                'birthday',
                'milestone',
                'referral',
                'seasonal',
                'social_action',
                'manual',
            ]);
            $table->json('trigger_value')->nullable();
            $table->integer('reward_value')->nullable();
            $table->integer('expiration_hours')->default(24);
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('active');
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->index('loyalty_programs_id');
            $table->index('status');
            $table->index('trigger_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_offers');
    }
};
