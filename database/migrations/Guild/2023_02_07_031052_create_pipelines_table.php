<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePipelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('pipelines', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('companies_id')->nullable()->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->integer('system_modules_id')->nullable()->index('system_modules_id');
            $table->string('name', 64)->nullable();
            $table->char('slug', 50)->nullable()->index('slug');
            $table->smallInteger('weight')->nullable()->index('weight');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_default')->default(0)->index('is_default');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');

            $table->index(['companies_id', 'system_modules_id', 'slug'], 'companies_id_system_modules_id_slug');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('pipelines');
    }
}
