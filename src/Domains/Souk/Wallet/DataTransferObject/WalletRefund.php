<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Orders\Models\Order;
use Spatie\LaravelData\Data;

class WalletRefund extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly UserInterface $user,
        public readonly Order $order,
        public readonly ?float $amount = null,
        public readonly ?string $reason = null,
        public readonly string $tag = 'default',
    ) {
    }
}
