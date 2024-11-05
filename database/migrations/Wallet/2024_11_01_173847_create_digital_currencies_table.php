<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDigitalCurrenciesTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('digital_currencies', function (Blueprint $table) {
            $table->id();
            $table->integer('apps_id');
            $table->uuid('uuid')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->integer('is_deleted')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('digital_currencies');
    }
}
