<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCountriesStatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('countries_states', function (Blueprint $table) {
            $table->foreign(['countries_id'], 'countries_states_ibfk_1')->references(['id'])->on('countries')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('countries_states', function (Blueprint $table) {
            $table->dropForeign('countries_states_ibfk_1');
        });
    }
}
