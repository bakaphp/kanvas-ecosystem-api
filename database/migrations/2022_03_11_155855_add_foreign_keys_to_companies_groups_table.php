<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCompaniesGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies_groups', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'companies_groups_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'companies_groups_ibfk_2')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('companies_groups', function (Blueprint $table) {
            $table->dropForeign('companies_groups_ibfk_1');
            $table->dropForeign('companies_groups_ibfk_2');
        });
    }
}
