<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Bavix\Wallet\Models\Wallet as WalletBase;

class Wallets extends WalletBase
{
    use HasFactory;

    protected $connection = 'wallet';

    public function balanceHistories()
    {
        return $this->hasMany(WalletsBalanceHistories::class);
    }
}
