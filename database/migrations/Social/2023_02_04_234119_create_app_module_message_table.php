<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppModuleMessageTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('app_module_message', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('message_id')->index('message_id');
            $table->integer('message_types_id')->index('message_types_id');
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->string('system_modules', 100)->nullable()->index('system_modules');
            $table->bigInteger('entity_id')->nullable()->index('entity_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['apps_id', 'system_modules', 'entity_id', 'is_deleted'], 'apps_id_system_modules_entity_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('app_module_message');
    }
}
