<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('commerce')->table('order_transitions_history', function (Blueprint $table) {
            $table->decimal('total_gross_amount', 16, 4)->nullable()->after('to_status_id');
            $table->decimal('discount_amount', 16, 4)->nullable()->after('total_gross_amount');
            $table->decimal('total_net_amount', 16, 4)->nullable()->after('discount_amount');
            $table->json('items_snapshot')->nullable()->after('total_net_amount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('commerce')->table('order_transitions_history', function (Blueprint $table) {
            $table->dropColumn(['total_gross_amount', 'discount_amount', 'total_net_amount', 'items_snapshot']);
        });
    }
};
