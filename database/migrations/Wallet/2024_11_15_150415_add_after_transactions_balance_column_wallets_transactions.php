<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kanvas\Wallet\Models\WalletsTransactions;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('wallet')->table($this->table(), static function (Blueprint $table) {
            $table->decimal('after_transaction_balance')
                ->default(0)
                ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropColumns($this->table());
    }

    private function table(): string
    {
        return (new WalletsTransactions())->getTable();
    }
};
