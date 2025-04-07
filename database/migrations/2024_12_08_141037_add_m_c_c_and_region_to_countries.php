<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        /**
         * where to find the db if you loose it https://mcc-mnc.net/
         */
        Schema::table('countries', function (Blueprint $table) {
            $table->string('mcc', 16)->nullable()->index()->after('code');
            $table->string('region', 240)->nullable()->index()->after('mcc');
        });
    }

    public function down()
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['mcc', 'region']); // Remove the fields if rolled back
        });
    }
};
