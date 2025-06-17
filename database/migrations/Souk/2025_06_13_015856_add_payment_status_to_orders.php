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
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('related_order_id')->nullable()->index()->after('id');
            $table->enum('payment_status', ['unpaid', 'pending_action', 'processing', 'paid', 'failed', 'refunded'])->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_status');
            $table->dropColumn('related_order_id');
            $table->enum('status', ['draft', 'completed', 'canceled', 'cancelled', 'pending', 'failed'])->nullable()->change();
        });
    }
};
