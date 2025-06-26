<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet;

use Bavix\Wallet\Models\Wallet as WalletBase;

class Wallet extends WalletBase
{
    protected $connection = 'commerce';
}
