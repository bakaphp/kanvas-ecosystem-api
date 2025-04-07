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
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropColumn('stripe_plan');
            $table->renameColumn('price_id', 'apps_plans_prices_id');
            $table->integer('apps_plans_prices_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->string('stripe_plan')->nullable();
            $table->renameColumn('apps_plans_prices_id', 'price_id');
            $table->string('price_id')->change();
        });
    }
};
