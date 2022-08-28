<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToAuditsDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('audits_details', function (Blueprint $table) {
            $table->foreign(['audits_id'], 'audits_details_ibfk_1')->references(['id'])->on('audits')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('audits_details', function (Blueprint $table) {
            $table->dropForeign('audits_details_ibfk_1');
        });
    }
}
