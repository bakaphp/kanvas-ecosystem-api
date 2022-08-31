<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToWebhooksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->foreign(['system_modules_id'], 'webhooks_ibfk_1')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'webhooks_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropForeign('webhooks_ibfk_1');
            $table->dropForeign('webhooks_ibfk_2');
        });
    }
}
