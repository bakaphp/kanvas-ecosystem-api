<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsOwnerTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_owner', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('companies_id')->index('companies_id');
            $table->string('firstname', 45);
            $table->string('lastname', 45)->nullable();
            $table->string('email', 45)->index('email');
            $table->string('phone', 45)->nullable();
            $table->string('address', 45)->nullable();
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_owner');
    }
}
