<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::connection('commerce')->table('order_types', function (Blueprint $table) {
            $table->unsignedInteger('total_statuses')->default(0)->after('name');
        });
    }

    public function down()
    {
        Schema::connection('commerce')->table('order_types', function (Blueprint $table) {
            $table->dropColumn('total_statuses');
        });
    }
};
