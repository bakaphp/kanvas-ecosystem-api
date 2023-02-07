<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_types', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->nullable()->default(0)->index('is_deleted');

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
        Schema::connection('crm')->dropIfExists('leads_types');
    }
}
