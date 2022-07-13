<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUserWebhooksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_webhooks', function (Blueprint $table) {
            $table->foreign(['webhooks_id'], 'user_webhooks_ibfk_1')->references(['id'])->on('webhooks')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'user_webhooks_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'user_webhooks_ibfk_3')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'user_webhooks_ibfk_4')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_webhooks', function (Blueprint $table) {
            $table->dropForeign('user_webhooks_ibfk_1');
            $table->dropForeign('user_webhooks_ibfk_2');
            $table->dropForeign('user_webhooks_ibfk_3');
            $table->dropForeign('user_webhooks_ibfk_4');
        });
    }
}
