<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Spatie\LaravelData\Data;

/**
 * What a provider answered about one e-CF. pdfBase64 is carried to be stored as a file, never persisted on the
 * fiscal row.
 *
 * reachedAuthority false: the provider refused it before DGII saw it, so the number is untouched.
 * numberConsumed is DGII's `secuenciaUtilizada` as-is (null when the provider does not pass it on).
 */
class EcfSubmission extends Data
{
    /**
     * @param string[] $qrLegends
     * @param string[] $messages
     * @param array<array-key, mixed>|null $rawResponse what the provider returned, as it returned it
     */
    public function __construct(
        public readonly EcfStatusEnum $status,
        public readonly string $encf,
        public readonly ?string $providerReference = null,
        public readonly ?string $securityCode = null,
        public readonly ?string $signedAt = null,
        public readonly ?string $qrUrl = null,
        public readonly array $qrLegends = [],
        public readonly array $messages = [],
        public readonly ?string $pdfBase64 = null,
        public readonly bool $wasDuplicated = false,
        public readonly bool $reachedAuthority = true,
        public readonly ?bool $numberConsumed = null,
        public readonly ?array $rawResponse = null,
    ) {
    }
}
