<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsRotationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_rotations', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('companies_id')->default(0)->index('companies_id');
            $table->string('name');
            $table->string('leads_rotations_email')->nullable()->index('leads_rotations_email');
            $table->integer('hits')->default(0)->index('hits');
            $table->dateTime('created_at')->useCurrent()->index('created_at');
            $table->dateTime('updated_at')->useCurrent()->index('updated_at');
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
        Schema::connection('crm')->dropIfExists('leads_rotations');
    }
}
