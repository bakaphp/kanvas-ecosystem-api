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
        Schema::connection('commerce')->table('order_statuses', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(1)->after('is_final');
            $table->index(['order_types_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('commerce')->table('order_statuses', function (Blueprint $table) {
            $table->dropIndex(['order_types_id', 'sequence']);
            $table->dropColumn('sequence');
        });
    }
};
