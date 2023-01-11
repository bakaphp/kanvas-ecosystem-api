<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserWebhooksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_webhooks', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('webhooks_id')->index('webhooks_id');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id')->index('companies_id');
            $table->string('url', 200);
            $table->string('method', 64);
            $table->string('format', 64);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_webhooks');
    }
}
