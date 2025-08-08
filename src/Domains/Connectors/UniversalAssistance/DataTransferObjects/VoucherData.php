<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\DataTransferObjects;

use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Enums\VoucherStatusEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\DocumentTypeEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\SaleTypeEnum;
use Spatie\LaravelData\Data;

class VoucherData extends Data
{
    public function __construct(
        public string $controlNumber,
        public string $channel,
        public string $line,
        public string $applicantResidenceCountry,
        public string $applicantDocumentNumber,
        public string $country,
        public string $stateProvince,
        public ?string $postProcessFlag = 'N',
        public ?string $seller = '',
        public ?string $saleType = null,
        public ?string $voucherStatus = null,
        public ?string $voucherReason = 'Activo',
        public ?string $billingStatus = 'Pendiente Facturación',
        public ?string $currency = 'USD',
        public ?string $applicantDocumentType = null,
        public ?Carbon $birthDate = null,
        public ?Carbon $startDate = null,
        public ?Carbon $endDate = null,
        public ?string $city = '',
        public ?string $postalCode = '',
        public ?string $address = ''
    ) {
        $this->saleType ??= SaleTypeEnum::ANNUAL->value;
        $this->voucherStatus ??= VoucherStatusEnum::ACTIVE->value;
        $this->applicantDocumentType ??= DocumentTypeEnum::DNI->value;
    }
}
