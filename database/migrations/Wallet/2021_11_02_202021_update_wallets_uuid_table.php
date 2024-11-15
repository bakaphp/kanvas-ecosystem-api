<?php

declare(strict_types=1);

use Bavix\Wallet\Internal\Service\IdentifierFactoryServiceInterface;
use Kanvas\Wallet\Models\Wallets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::connection('wallet')->hasColumn($this->table(), 'uuid')) {
            return;
        }

        // upgrade from 6.x
        Schema::connection('wallet')->table($this->table(), static function (Blueprint $table) {
            $table->uuid('uuid')
                ->after('slug')
                ->nullable()
                ->unique();
        });

        Wallet::connection('wallet')->query()->chunk(10000, static function (Collection $wallets) {
            $wallets->each(function (Wallet $wallet) {
                $wallet->uuid = app(IdentifierFactoryServiceInterface::class)->generate();
                $wallet->save();
            });
        });

        Schema::connection('wallet')->table($this->table(), static function (Blueprint $table) {
            $table->uuid('uuid')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::connection('wallet')->table($this->table(), function (Blueprint $table) {
            if (Schema::hasColumn($this->table(), 'uuid')) {
                $table->dropIndex('wallets_uuid_unique');
                $table->dropColumn('uuid');
            }
        });
    }

    private function table(): string
    {
        return (new Wallets())->getTable();
    }
};
