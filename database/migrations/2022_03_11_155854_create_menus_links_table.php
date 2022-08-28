<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenusLinksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('menus_links', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('menus_id')->index('menus_id');
            $table->integer('parent_id')->index('parent_id');
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->string('url', 64);
            $table->string('title', 64);
            $table->smallInteger('position')->index('position');
            $table->string('icon_url')->nullable();
            $table->string('icon_class')->nullable();
            $table->string('route')->nullable();
            $table->tinyInteger('is_published')->index('is_published');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('menus_links');
    }
}
