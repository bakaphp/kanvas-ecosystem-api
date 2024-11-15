<?php

declare(strict_types=1);

use Kanvas\Wallet\Models\WalletsTransactionsLogs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::connection('wallet')->table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['from_type', 'from_id']);
            $table->dropIndex(['to_type', 'to_id']);

            $table->index('from_id');
            $table->index('to_id');
        });

        Schema::connection('wallet')->dropColumns($this->table(), ['from_type', 'to_type']);
    }

    public function down(): void
    {
        Schema::connection('wallet')->table($this->table(), static function (Blueprint $table) {
            $table->string('from_type')
                ->after('from_id');
            $table->string('to_type')
                ->after('to_id');
        });
    }

    private function table(): string
    {
        return (new WalletsTransactionsLogs())->getTable();
    }
};
