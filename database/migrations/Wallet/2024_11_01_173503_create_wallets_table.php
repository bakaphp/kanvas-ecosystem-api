<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->integer('apps_id');
            $table->integer('users_id');
            $table->decimal('balance', 18, 2);
            $table->integer('currency_id');
            $table->timestamps();
            $table->integer('is_deleted')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallets');
    }
}
