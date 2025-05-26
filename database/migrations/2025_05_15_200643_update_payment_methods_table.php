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
        Schema::table('payment_methods_credentials', function (Blueprint $table) {
            $table->string('instrument_identifier_id', 64)->nullable()->after('expiration_date');
            $table->string('processor', 64)->nullable()->after('instrument_identifier_id');
            $table->json('metadata')->nullable()->after('processor');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_methods_credentials', function (Blueprint $table) {
            $table->dropColumn('instrument_identifier_id');
            $table->dropColumn('processor');
            $table->dropColumn('metadata');
        });
    }
};
