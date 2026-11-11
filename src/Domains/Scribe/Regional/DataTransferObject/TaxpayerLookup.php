<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Regional\DataTransferObject;

final readonly class TaxpayerLookup
{
    public const string FOUND = 'found';
    public const string NOT_REGISTERED = 'not_registered';
    public const string INVALID_FORMAT = 'invalid_format';
    public const string NO_PROVIDER = 'no_provider';
    public const string FAILED = 'failed';

    public function __construct(
        public string $status,
        public string $taxId,
        public ?string $legalName = null,
    ) {
    }

    public function found(): bool
    {
        return $this->status === self::FOUND;
    }

    public function type(): string
    {
        return strlen($this->taxId) === 9 ? 'rnc' : 'cedula';
    }
}
