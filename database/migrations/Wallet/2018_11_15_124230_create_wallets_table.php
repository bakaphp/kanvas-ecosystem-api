<?php

declare(strict_types=1);

use Kanvas\Wallet\Models\WalletsTransactions;
use Kanvas\Wallet\Models\Wallets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::connection('wallet')->create($this->table(), static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('holder');
            $table->string('name');
            $table->string('slug')
                ->index();
            $table->uuid('uuid')
                ->unique();
            $table->string('description')
                ->nullable();
            $table->json('meta')
                ->nullable();
            $table->decimal('balance', 64, 0)
                ->default(0);
            $table->unsignedSmallInteger('decimal_places')
                ->default(2);
            $table->timestamps();

            $table->unique(['holder_type', 'holder_id', 'slug']);
        });

        Schema::connection('wallet')->table($this->transactionTable(), function (Blueprint $table) {
            $table->foreign('wallet_id')
                ->references('id')
                ->on($this->table())
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('wallet')->disableForeignKeyConstraints();
        Schema::connection('wallet')->drop($this->table());
    }

    private function table(): string
    {
        return (new Wallets())->getTable();
    }

    private function transactionTable(): string
    {
        return (new WalletsTransactions())->getTable();
    }
};
