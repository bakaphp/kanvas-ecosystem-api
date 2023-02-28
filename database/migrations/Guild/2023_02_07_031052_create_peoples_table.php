<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeoplesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('peoples', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->index('uuid');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->string('name');
            $table->date('dob')->nullable()->index('dob');
            $table->string('google_contact_id', 128)->nullable();
            $table->string('apple_contact_id', 128)->nullable();
            $table->string('facebook_contact_id', 128)->nullable();
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
        Schema::connection('crm')->dropIfExists('peoples');
    }
}
