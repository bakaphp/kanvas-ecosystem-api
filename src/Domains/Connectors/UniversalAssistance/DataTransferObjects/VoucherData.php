<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\DataTransferObjects;

use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Enums\EstadoVoucherEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoDocumentoEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoVentaEnum;

class VoucherData
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
        $this->saleType ??= TipoVentaEnum::ANUAL->value;
        $this->voucherStatus ??= EstadoVoucherEnum::ACTIVO->value;
        $this->applicantDocumentType ??= TipoDocumentoEnum::DNI->value;
    }

    public function toArray(): array
    {
        $data = [
            'control_number' => $this->controlNumber,
            'post_process_flag' => $this->postProcessFlag,
            'seller' => $this->seller,
            'channel' => $this->channel,
            'sale_type' => $this->saleType,
            'line' => $this->line,
            'voucher_status' => $this->voucherStatus,
            'voucher_reason' => $this->voucherReason,
            'billing_status' => $this->billingStatus,
            'currency' => $this->currency,
            'applicant_residence_country' => $this->applicantResidenceCountry,
            'applicant_document_type' => $this->applicantDocumentType,
            'applicant_document_number' => $this->applicantDocumentNumber,
            'country' => $this->country,
            'state_province' => $this->stateProvince,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'address' => $this->address,
        ];

        if ($this->birthDate) {
            $data['birth_date'] = $this->birthDate->toDateString();
        }

        if ($this->startDate) {
            $data['start_date'] = $this->startDate->toDateString();
        }

        if ($this->endDate) {
            $data['end_date'] = $this->endDate->toDateString();
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            controlNumber: $data['control_number'],
            channel: $data['channel'],
            line: $data['line'],
            applicantResidenceCountry: $data['applicant_residence_country'],
            applicantDocumentNumber: $data['applicant_document_number'],
            country: $data['country'],
            stateProvince: $data['state_province'],
            postProcessFlag: $data['post_process_flag'] ?? 'N',
            seller: $data['seller'] ?? '',
            saleType: $data['sale_type'] ?? null,
            voucherStatus: $data['voucher_status'] ?? null,
            voucherReason: $data['voucher_reason'] ?? 'Activo',
            billingStatus: $data['billing_status'] ?? 'Pendiente Facturación',
            currency: $data['currency'] ?? 'USD',
            applicantDocumentType: $data['applicant_document_type'] ?? null,
            birthDate: isset($data['birth_date']) ? Carbon::parse($data['birth_date']) : null,
            startDate: isset($data['start_date']) ? Carbon::parse($data['start_date']) : null,
            endDate: isset($data['end_date']) ? Carbon::parse($data['end_date']) : null,
            city: $data['city'] ?? '',
            postalCode: $data['postal_code'] ?? '',
            address: $data['address'] ?? ''
        );
    }
}
