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
        Schema::connection('commerce')->table('loyalty_tier_memberships', function (Blueprint $table) {
            $table->decimal('lifetime_points', 15, 2)->default(0)->change();
            $table->decimal('current_points', 15, 2)->default(0)->change();
        });

        Schema::connection('commerce')->table('order_loyalty_points', function (Blueprint $table) {
            $table->decimal('points_earned', 15, 2)->default(0)->change();
            $table->decimal('points_redeemed', 15, 2)->default(0)->change();
        });

        Schema::connection('commerce')->table('referral_redemptions', function (Blueprint $table) {
            $table->decimal('referrer_points_awarded', 15, 2)->default(0)->change();
        });

        Schema::connection('commerce')->table('order_referral_codes', function (Blueprint $table) {
            $table->decimal('referrer_points_earned', 15, 2)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->table('loyalty_tier_memberships', function (Blueprint $table) {
            $table->bigInteger('lifetime_points')->default(0)->change();
            $table->bigInteger('current_points')->default(0)->change();
        });

        Schema::connection('commerce')->table('order_loyalty_points', function (Blueprint $table) {
            $table->integer('points_earned')->default(0)->change();
            $table->integer('points_redeemed')->default(0)->change();
        });

        Schema::connection('commerce')->table('referral_redemptions', function (Blueprint $table) {
            $table->integer('referrer_points_awarded')->default(0)->change();
        });

        Schema::connection('commerce')->table('order_referral_codes', function (Blueprint $table) {
            $table->integer('referrer_points_earned')->default(0)->change();
        });
    }
};
