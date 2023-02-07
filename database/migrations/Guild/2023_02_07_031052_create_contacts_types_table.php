<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContactsTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('contacts_types', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('companies_id')->index('companies_id');
            $table->integer('users_id')->index('users_id');
            $table->string('name');
            $table->string('icon')->nullable();
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
        Schema::connection('crm')->dropIfExists('contacts_types');
    }
}
