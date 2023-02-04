<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTagsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('tags', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->string('name');
            $table->string('slug')->index('slugs');
            $table->integer('weight')->default(0)->index('weight');
            $table->integer('is_feature')->default(0)->index('is_feature');
            $table->boolean('status')->default(false)->index('status');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['apps_id', 'companies_id'], 'apps_id_companies_id');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'apps_id_companies_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('tags');
    }
}
