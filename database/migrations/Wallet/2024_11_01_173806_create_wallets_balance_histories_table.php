<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsBalanceHistoriesTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('wallets_balance_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('apps_id');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->foreignId('transaction_id')->constrained('wallets_transactions');
            $table->decimal('balance', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('wallet_id');
            $table->index('transaction_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallets_balance_histories');
    }
}