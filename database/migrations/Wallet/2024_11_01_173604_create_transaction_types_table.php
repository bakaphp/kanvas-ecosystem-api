<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionTypesTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->integer('is_deleted')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_types');
    }
}