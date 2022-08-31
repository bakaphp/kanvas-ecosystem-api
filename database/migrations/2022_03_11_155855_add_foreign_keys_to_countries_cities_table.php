<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCountriesCitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('countries_cities', function (Blueprint $table) {
            $table->foreign(['countries_id'], 'countries_cities_ibfk_1')->references(['id'])->on('countries')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['states_id'], 'countries_cities_ibfk_2')->references(['id'])->on('countries_states')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('countries_cities', function (Blueprint $table) {
            $table->dropForeign('countries_cities_ibfk_1');
            $table->dropForeign('countries_cities_ibfk_2');
        });
    }
}
