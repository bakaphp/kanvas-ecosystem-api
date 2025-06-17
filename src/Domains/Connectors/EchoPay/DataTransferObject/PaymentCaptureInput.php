<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentCaptureInput extends Data
{
    public string $transactionId;
    public string $orderCode;
    public string $currency;
    public string $totalAmount;
}
