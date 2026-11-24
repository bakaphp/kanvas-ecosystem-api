<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;

/**
 * One e-CF as the provider's issued-documents export lists it: what the authority already decided about a
 * number Kanvas never sent. The export carries totals, not lines.
 */
final readonly class IssuedEcfExportRow
{
    public function __construct(
        public string $encf,
        public Carbon $issuedAt,
        public ?Carbon $authorizedAt,
        public EcfEnvironmentEnum $environment,
        public FiscalDocumentStatusEnum $status,
        public ?string $authorityStatus,
        public string $customerName,
        public ?string $customerTaxId,
        public float $subtotal,
        public float $tax,
        public float $total,
        public string $currency,
        public float $rate,
        public ?string $reference,
        public ?string $trackId,
        public ?string $providerRef,
        public int $rowNumber,
    ) {
    }

    public function documentTypeCode(): string
    {
        return substr($this->encf, 1, 2);
    }

    public function isRejected(): bool
    {
        return $this->status === FiscalDocumentStatusEnum::REJECTED;
    }
}
