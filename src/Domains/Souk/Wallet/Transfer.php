<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet;

use Bavix\Wallet\Models\Transfer as ModelsTransfer;

class Transfer extends ModelsTransfer
{
    protected $connection = 'commerce';
}
