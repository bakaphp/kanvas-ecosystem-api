<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationsRelatedTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('organizations_related', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('organizations_id')->nullable()->index('organizations_id');
            $table->bigInteger('related_organizations_id')->nullable()->index('related_organizations_id');
            $table->tinyInteger('organizations_relations_type_id')->nullable()->index('organizations_relations_type_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('organizations_related');
    }
}
