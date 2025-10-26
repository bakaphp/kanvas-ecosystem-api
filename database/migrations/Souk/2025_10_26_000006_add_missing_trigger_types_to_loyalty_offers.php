<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old enum constraint and create a new one with all trigger types
        Schema::connection('commerce')->table('loyalty_offers', function (Blueprint $table) {
            // For MySQL, we need to modify the column type
            // This changes the ENUM values to include the missing trigger types
            DB::connection('commerce')->statement(
                "ALTER TABLE loyalty_offers MODIFY trigger_type ENUM(
                    'first_purchase',
                    'first_product_type',
                    'first_category',
                    'first_tier_purchase',
                    'tier_upgrade',
                    'birthday',
                    'milestone',
                    'referral',
                    'seasonal',
                    'social_action',
                    'manual'
                ) NOT NULL"
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->table('loyalty_offers', function (Blueprint $table) {
            // Revert to original ENUM values
            DB::connection('commerce')->statement(
                "ALTER TABLE loyalty_offers MODIFY trigger_type ENUM(
                    'first_purchase',
                    'tier_upgrade',
                    'birthday',
                    'milestone',
                    'referral',
                    'seasonal',
                    'social_action',
                    'manual'
                ) NOT NULL"
            );
        });
    }
};
