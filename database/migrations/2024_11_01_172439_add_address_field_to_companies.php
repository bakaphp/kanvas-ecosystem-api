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
        Schema::table('companies', function (Blueprint $table) {
            //'
            $table->integer('countries_id')->unsigned()->nullable()->after('currency_id')->index('countries_id');
            $table->integer('states_id')->unsigned()->nullable()->after('currency_id')->index('states_id');
            $table->integer('cities_id')->unsigned()->nullable()->after('currency_id')->index('cities_id');
            $table->string('address_2')->nullable()->after('phone');
            $table->string('city')->nullable()->after('phone');
            $table->string('state')->nullable()->after('phone');
            $table->string('country')->nullable()->after('phone');
            $table->string('zip', 10)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('countries_id');
            $table->dropColumn('states_id');
            $table->dropColumn('cities_id');
            $table->dropColumn('address_2');
            $table->dropColumn('city');
            $table->dropColumn('state');
        });
    }
};
