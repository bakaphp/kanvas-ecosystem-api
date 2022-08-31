<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebhooksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->string('name', 64);
            $table->string('description', 64);
            $table->string('action', 64);
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
        Schema::dropIfExists('webhooks');
    }
}
