<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTransactionsLogsTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('wallets_transactions_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('apps_id');
            $table->uuid('uuid', 64)->nullable();
            $table->foreignId('transaction_id')->constrained('wallets_transactions');
            $table->text('details');
            $table->timestamps();

            $table->index('transaction_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallets_transactions_logs');
    }
}
