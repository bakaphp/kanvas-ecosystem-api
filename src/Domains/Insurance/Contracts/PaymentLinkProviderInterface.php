<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\PaymentLinkResult;
use Kanvas\Souk\Orders\Models\Order;

interface PaymentLinkProviderInterface
{
    public function requestPaymentLink(Order $order, bool $byEmail = false): PaymentLinkResult;
}
