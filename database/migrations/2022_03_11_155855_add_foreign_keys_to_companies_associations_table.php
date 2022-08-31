<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCompaniesAssociationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies_associations', function (Blueprint $table) {
            $table->foreign(['companies_id'], 'companies_associations_ibfk_1')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('companies_associations', function (Blueprint $table) {
            $table->dropForeign('companies_associations_ibfk_1');
        });
    }
}
