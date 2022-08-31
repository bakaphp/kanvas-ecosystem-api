<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->integer('id', true);
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->integer('parent_id')->default(0)->index('parents_id');
            $table->string('name', 64);
            $table->string('key')->index('key');
            $table->text('description')->nullable();
            $table->text('template')->nullable();
            $table->string('icon_url', 50)->nullable();
            $table->tinyInteger('with_realtime')->default(0)->index('with_realtime');
            $table->decimal('weight', 3)->default(0)->index('weight');
            $table->boolean('is_published')->default(true)->index('is_published');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->index(['apps_id', 'system_modules_id'], 'apps_id_system_modules_id');
            $table->index(['apps_id', 'system_modules_id', 'parent_id'], 'apps_id_system_modules_id_parents_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notification_types');
    }
}
