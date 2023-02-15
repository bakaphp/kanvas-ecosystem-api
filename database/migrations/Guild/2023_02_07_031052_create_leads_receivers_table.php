<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsReceiversTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_receivers', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->index('uuid');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('companies_branches_id')->nullable();
            $table->string('name', 100);
            $table->bigInteger('users_id')->index('users_id');
            $table->bigInteger('agents_id')->index('agents_id');
            $table->bigInteger('rotations_id')->default(0)->index('rotations_id');
            $table->string('source_name');
            $table->longText('template')->nullable();
            $table->integer('total_leads')->default(0)->index('total_leads');
            $table->integer('is_default')->default(0)->index('is_default');
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
        Schema::connection('crm')->dropIfExists('leads_receivers');
    }
}
