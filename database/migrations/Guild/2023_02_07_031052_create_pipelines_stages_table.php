<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePipelinesStagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('pipelines_stages', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('pipelines_id')->nullable()->index('pipelines_id');
            $table->string('name', 64)->nullable();
            $table->tinyInteger('has_rotting_days')->nullable()->default(0)->index('has_rotting_days');
            $table->tinyInteger('rotting_days')->nullable()->default(0)->index('rotting_days');
            $table->smallInteger('weight')->nullable()->index('weight');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('pipelines_stages');
    }
}
