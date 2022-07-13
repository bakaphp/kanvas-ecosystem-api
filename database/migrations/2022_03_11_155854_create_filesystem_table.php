<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilesystemTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('filesystem', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('companies_id')->index('companies_id');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->integer('users_id')->index('users_id');
            $table->string('name');
            $table->string('path');
            $table->string('url');
            $table->string('size');
            $table->string('file_type', 16)->index('file_type');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable();

            $table->index(['id', 'is_deleted'], 'id_is_deleted');
            $table->index(['id', 'file_type', 'is_deleted'], 'id_file_type_is_deleted');
            $table->index(['id', 'companies_id', 'is_deleted'], 'filesystemindex2');
            $table->index(['companies_id', 'is_deleted'], 'companies_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('filesystem');
    }
}
