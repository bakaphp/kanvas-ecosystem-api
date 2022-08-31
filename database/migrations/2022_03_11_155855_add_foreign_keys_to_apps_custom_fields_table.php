<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToAppsCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('apps_custom_fields', function (Blueprint $table) {
            $table->foreign(['companies_id'], 'apps_custom_fields_ibfk_1')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'apps_custom_fields_ibfk_2')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('apps_custom_fields', function (Blueprint $table) {
            $table->dropForeign('apps_custom_fields_ibfk_1');
            $table->dropForeign('apps_custom_fields_ibfk_2');
        });
    }
}
