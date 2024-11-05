<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTransactionsTable extends Migration
{
    public function up()
    {
        Schema::connection('wallet')->create('wallets_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->integer('apps_id');
            $table->integer('users_id');
            $table->integer('variant_id');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->integer('status');
            $table->text('concept');
            $table->decimal('amount', 18, 2);
            $table->decimal('transaction_fee', 18, 2);
            $table->foreignId('transaction_type_id')->constrained('transaction_types');
            $table->timestamps();
            $table->integer('is_deleted')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallets_transactions');
    }
}