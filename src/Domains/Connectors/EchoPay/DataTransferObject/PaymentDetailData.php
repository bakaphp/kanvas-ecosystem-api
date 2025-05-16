<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class PaymentDetailData extends Data
{
    public function __construct(
        public readonly string $orderCode,
        public readonly string $paymentInstrumentId,
        public readonly OrderInformationData $orderInformation,
        public readonly ?DeviceInformationData $deviceInformation,
        public readonly ?ConsumerAuthenticationInformationData $consumerAuthenticationInformation
    ) {
    }
}
