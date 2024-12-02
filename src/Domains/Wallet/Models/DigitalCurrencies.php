<?php

namespace Kanvas\Wallet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigitalCurrencies extends Model
{
    use HasFactory;

    protected $fillable = [
        'apps_id',
        'uuid',
        'name',
        'description',
        'is_deleted',
    ];
}