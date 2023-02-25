<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->index('uuid');
            $table->bigInteger('users_id')->index('users_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->unsignedBigInteger('companies_branches_id')->index('companies_branches_id');
            $table->bigInteger('leads_receivers_id')->index('leads_receivers_id');
            $table->bigInteger('leads_owner_id')->index('leads_owner_id');
            $table->bigInteger('leads_status_id')->default(1)->index('leads_status_id');
            $table->bigInteger('pipeline_id')->default(0)->index('pipeline_id');
            $table->bigInteger('pipeline_stage_id')->default(0)->index('pipeline_stage_id');
            $table->integer('people_id')->nullable()->index('people_id');
            $table->integer('organization_id')->nullable()->index('organization_id');
            $table->integer('leads_types_id')->nullable()->default(0)->index('leads_types_id');
            $table->integer('leads_sources_id')->nullable()->default(0)->index('leads_sources_id');
            $table->integer('status')->nullable()->default(0)->index('status');
            $table->text('reason_lost')->nullable();
            $table->string('title')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable()->index('email');
            $table->string('phone', 45)->nullable();
            $table->longText('description')->nullable();
            $table->boolean('is_duplicated')->default(false)->index('is_duplicated');
            $table->boolean('third_party_sync_status')->default(true)->index('third_party_sync_status');
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
        Schema::connection('crm')->dropIfExists('leads');
    }
}
