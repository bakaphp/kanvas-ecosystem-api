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
        Schema::connection('commerce')->create('order_referral_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('orders_id');
            $table->unsignedBigInteger('referral_codes_id');
            $table->unsignedBigInteger('referrer_user_id');
            $table->unsignedBigInteger('referee_user_id');
            $table->integer('referrer_points_earned')->default(0);
            $table->decimal('referee_discount_applied', 12, 2)->default(0.0);
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->foreign('orders_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('referral_codes_id')->references('id')->on('referral_codes')->onDelete('cascade');
            
            $table->unique(['orders_id', 'referral_codes_id']);
            $table->index('orders_id');
            $table->index('referral_codes_id');
            $table->index('referrer_user_id');
            $table->index('referee_user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('order_referral_codes');
    }
};