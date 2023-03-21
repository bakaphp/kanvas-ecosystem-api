<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeoplesContactsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('peoples_contacts', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('contacts_types_id')->index('contacts_types_id');
            $table->bigInteger('peoples_id')->default(0)->index('peoples_id');
            $table->string('value')->nullable();
            $table->smallInteger('weight')->default(0)->index('weight');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('peoples_contacts');
    }
}
