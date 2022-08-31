<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilesystemEntitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('filesystem_entities', function (Blueprint $table) {
            $table->integer('id', true);
            $table->unsignedInteger('filesystem_id')->index('filesystem_id');
            $table->char('entity_id', 36)->default('');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->string('field_name', 50)->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false);

            $table->unique(['filesystem_id', 'entity_id', 'companies_id', 'system_modules_id'], 'uniqueentityfilesytem');
            $table->index(['filesystem_id', 'entity_id', 'companies_id', 'system_modules_id', 'field_name'], 'filesystem_attachment');
            $table->index(['filesystem_id', 'entity_id', 'companies_id', 'system_modules_id', 'field_name', 'is_deleted'], 'filesystem_attachmenidex2');
            $table->index(['filesystem_id', 'entity_id', 'system_modules_id'], 'entitiesid');
            $table->index(['entity_id', 'system_modules_id', 'is_deleted'], 'entity_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('filesystem_entities');
    }
}
