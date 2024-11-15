<?php

declare(strict_types=1);

use Kanvas\Wallet\Models\WalletsTransactions;
use Kanvas\Wallet\Models\Wallets;
use Kanvas\Wallet\Models\WalletsTransactionsLogs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::connection('wallet')->table((new Wallets())->getTable(), static function (Blueprint $table) {
            $table->softDeletesTz();
        });
        Schema::connection('wallet')->table((new WalletsTransactionsLogs())->getTable(), static function (Blueprint $table) {
            $table->softDeletesTz();
        });
        Schema::connection('wallet')->table((new WalletsTransactions())->getTable(), static function (Blueprint $table) {
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::connection('wallet')->table((new Wallets())->getTable(), static function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::connection('wallet')->table((new WalletsTransactionsLogs())->getTable(), static function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::connection('wallet')->table((new WalletsTransactions())->getTable(), static function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
