<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Bavix\Wallet\Models\Transfer;

class WalletsTransactionsLogs extends Transfer
{
    use HasFactory;

    protected $connection = 'wallet';

    // protected $fillable = [
    //     'apps_id',
    //     'uuid',
    //     'transaction_id',
    //     'details',
    // ];

    // public function transaction()
    // {
    //     return $this->belongsTo(WalletsTransactions::class, 'transaction_id');
    // }
}