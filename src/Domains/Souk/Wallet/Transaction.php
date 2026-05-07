<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet;

use Bavix\Wallet\Enums\TransactionType;
use Bavix\Wallet\Models\Transaction as ModelsTransaction;

class Transaction extends ModelsTransaction
{
    protected $connection = 'commerce';

    public function getTypeStringAttribute(): string
    {
        /** @var TransactionType $type */
        $type = $this->getAttribute('type');

        return $type->value;
    }
}
