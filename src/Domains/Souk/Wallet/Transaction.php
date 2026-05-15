<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet;

use BackedEnum;
use Bavix\Wallet\Models\Transaction as ModelsTransaction;

class Transaction extends ModelsTransaction
{
    protected $connection = 'commerce';

    public function getTypeStringAttribute(): string
    {
        $type = $this->getAttribute('type');

        return $type instanceof BackedEnum ? (string) $type->value : (string) $type;
    }
}
