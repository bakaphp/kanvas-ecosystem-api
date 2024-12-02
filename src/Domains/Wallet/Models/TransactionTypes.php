<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionTypes extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_deleted',
    ];
}