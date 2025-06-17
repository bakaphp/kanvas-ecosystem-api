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
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained('orders')
                ->after('id')
                ->cascadeOnDelete();
            $table->string('path')->nullable()->index();
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
            $table->dropColumn('parent_id');
            $table->dropColumn('path');
            $table->enum('status', ['draft', 'completed', 'canceled', 'cancelled', 'pending', 'failed'])->nullable()->change();
        });
    }
};
