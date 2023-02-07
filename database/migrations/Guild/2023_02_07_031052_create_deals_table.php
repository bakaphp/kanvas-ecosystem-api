<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDealsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('deals', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->index('uuid');
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('owner_id')->index('owner_id');
            $table->integer('status_id')->default(1)->index('status_id');
            $table->bigInteger('pipeline_id')->default(0)->index('pipeline_id');
            $table->bigInteger('pipeline_stage_id')->default(0)->index('pipeline_stage_id');
            $table->integer('people_id')->nullable()->default(0)->index('people_id');
            $table->integer('organization_id')->nullable()->default(0)->index('organization_id');
            $table->integer('status')->nullable()->default(0);
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('deals');
    }
}
