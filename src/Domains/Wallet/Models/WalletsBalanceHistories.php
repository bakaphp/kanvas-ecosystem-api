<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletsBalanceHistories extends Model
{
    use HasFactory;

    protected $fillable = [
        'apps_id',
        'wallet_id',
        'transaction_id',
        'balance',
        'created_at',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction()
    {
        return $this->belongsTo(WalletsTransactions::class, 'transaction_id');
    }
}