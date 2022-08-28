<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesAssociationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies_associations', function (Blueprint $table) {
            $table->integer('companies_groups_id');
            $table->integer('companies_id')->index('companies_id');
            $table->boolean('is_default')->nullable()->default(false);
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->index(['companies_groups_id', 'companies_id', 'is_default'], 'companies_groups_id_companies_id_is_default');
            $table->primary(['companies_groups_id', 'companies_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies_associations');
    }
}
