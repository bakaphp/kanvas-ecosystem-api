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
        Schema::table('order_transitions_history', function (Blueprint $table) {
            $table->boolean('is_current')->default(false);
            $table->bigInteger('from_status_id')->nullable()->change();
            $table->bigInteger('transition_id')->nullable()->change();
            $table->timestamp('ended_at')->nullable();
            $table->bigInteger('ended_by')->nullable();
            $table->bigInteger('duration_in_seconds')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_transitions_history', function (Blueprint $table) {
            $table->dropColumn('is_current');
            $table->dropColumn('ended_at');
            $table->dropColumn('ended_by');
            $table->dropColumn('duration_in_seconds');
        });
    }
};
