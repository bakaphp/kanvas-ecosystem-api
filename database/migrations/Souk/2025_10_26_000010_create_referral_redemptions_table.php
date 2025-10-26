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
        Schema::connection('commerce')->create('referral_redemptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('referral_codes_id');
            $table->unsignedBigInteger('referrer_user_id');
            $table->unsignedBigInteger('referee_user_id');
            $table->unsignedBigInteger('orders_id')->nullable();
            $table->unsignedBigInteger('discounts_id')->nullable();
            
            // Rewards given
            $table->integer('referrer_points_awarded')->default(0);
            $table->decimal('referee_discount_amount', 12, 2)->default(0.0);
            
            // Status
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('redeemed_at')->nullable();
            
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->foreign('referral_codes_id')->references('id')->on('referral_codes')->onDelete('cascade');
            $table->foreign('orders_id')->references('id')->on('orders')->onDelete('set null');
            
            $table->index('referrer_user_id');
            $table->index('referee_user_id');
            $table->index('status');
            $table->index('redeemed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('referral_redemptions');
    }
};