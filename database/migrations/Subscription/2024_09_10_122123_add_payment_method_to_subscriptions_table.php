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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Check if 'apps_plans_id' column doesn't exist, then add it as a big integer
            if (! Schema::hasColumn('subscriptions', 'apps_plans_id')) {
                $table->unsignedBigInteger('apps_plans_id')->nullable();
            }

            // Check if 'payment_method_id' column doesn't exist, then add it
            if (! Schema::hasColumn('subscriptions', 'payment_method_id')) {
                $table->string('payment_method_id')->nullable()->after('apps_plans_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Drop 'payment_method_id' if it exists
            if (Schema::hasColumn('subscriptions', 'payment_method_id')) {
                $table->dropColumn('payment_method_id');
            }

            // Drop 'apps_plans_id' if it exists
            if (Schema::hasColumn('subscriptions', 'apps_plans_id')) {
                $table->dropColumn('apps_plans_id');
            }
        });
    }
};
