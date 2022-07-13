<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCustomFiltersConditionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_filters_conditions', function (Blueprint $table) {
            $table->foreign(['custom_filter_id'], 'custom_filters_conditions_ibfk_1')->references(['id'])->on('custom_filters')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_filters_conditions', function (Blueprint $table) {
            $table->dropForeign('custom_filters_conditions_ibfk_1');
        });
    }
}
