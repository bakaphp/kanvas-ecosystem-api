<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Bavix\Wallet\Models\Transaction;

class WalletsTransactions extends Transaction
{
    use HasFactory;

    protected $connection = 'wallet';

    public function transactionType()
    {
        return $this->belongsTo(TransactionTypes::class);
    }

    public function logs()
    {
        return $this->hasMany(WalletsTransactionsLogs::class, 'transaction_id');
    }
}