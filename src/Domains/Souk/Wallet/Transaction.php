<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet;

use Bavix\Wallet\Models\Transaction as ModelsTransaction;

class Transaction extends ModelsTransaction
{
    protected $connection = 'commerce';
}
