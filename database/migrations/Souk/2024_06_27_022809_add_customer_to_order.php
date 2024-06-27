<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('region_id')->after('companies_id')->index();
            $table->unsignedBigInteger('people_id')->after('user_phone')->index();
            $table->enum('fulfillment_status', ['pending', 'fulfilled', 'canceled'])->after('status')->default('pending')->index();
            //shipping_date
            $table->timestamp('estimate_shipping_date')->nullable()->after('private_metadata');
            $table->timestamp('shipped_date')->nullable()->after('private_metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('people_id');
            $table->dropColumn('region_id');
        });
    }
};
