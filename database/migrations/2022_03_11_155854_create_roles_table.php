<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRolesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 32);
            $table->text('description')->nullable();
            $table->integer('scope');
            $table->integer('companies_id')->index('companies_id');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_default')->default(0)->index('is_default');
            $table->integer('is_active')->default(1)->index('is_active');
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
        Schema::dropIfExists('roles');
    }
}
