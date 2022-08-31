<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateResourcesAccessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resources_accesses', function (Blueprint $table) {
            $table->integer('resources_id');
            $table->string('resources_name', 32);
            $table->string('access_name', 32);
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['resources_id', 'resources_name', 'access_name', 'apps_id'], 're_acc_foreigh');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('resources_accesses');
    }
}
