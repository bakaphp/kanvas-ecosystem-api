<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSystemModulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('system_modules', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 100);
            $table->string('slug', 100)->index('slug');
            $table->string('model_name', 100)->index('model_name');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('parents_id')->nullable()->default(0)->index('parents_id');
            $table->integer('menu_order')->nullable()->index('menu_order');
            $table->integer('show')->nullable()->default(1)->index('show');
            $table->boolean('use_elastic')->nullable()->default(false)->index('use_elastic');
            $table->longText('browse_fields')->nullable();
            $table->text('bulk_actions')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');
            $table->string('mobile_component_type', 64)->nullable();
            $table->string('mobile_navigation_type', 64)->nullable();
            $table->integer('mobile_tab_index')->nullable()->default(0);
            $table->boolean('protected')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('system_modules');
    }
}
