<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('organizations', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->index('uuid');
            $table->bigInteger('companies_id')->nullable()->index('companies_id');
            $table->bigInteger('users_id')->nullable()->index('users_id');
            $table->string('name', 128)->nullable();
            $table->text('address')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('organizations');
    }
}
