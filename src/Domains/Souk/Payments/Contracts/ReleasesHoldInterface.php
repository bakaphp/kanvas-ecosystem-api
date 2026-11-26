<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Models\Payments;

interface ReleasesHoldInterface
{
    public function releaseHold(
        Payments $payment,
        Order $order,
        float $amount,
        string $reason
    ): VoidResult;
}
