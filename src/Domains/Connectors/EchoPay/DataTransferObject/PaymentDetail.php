<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentDetail extends Data
{
    public function __construct(
        public readonly string $orderCode,
        public readonly string $paymentInstrumentId,
        public readonly OrderInformation $orderInformation,
        public readonly ?DeviceInformation $deviceInformation,
        public readonly ?ConsumerAuthenticationInformation $consumerAuthenticationInformation
    ) {
    }
}
