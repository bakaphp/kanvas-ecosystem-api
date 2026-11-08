<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Regional\DataTransferObject;

final readonly class NcfValidation
{
    public const string MISSING = 'missing';
    public const string BAD_FORMAT = 'bad_format';
    public const string WRONG_TYPE = 'wrong_type';

    public function __construct(
        public ?string $ncf,
        public ?string $series,
        public ?string $type,
        public ?string $code = null,
        public ?string $reason = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->code === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ncf' => $this->ncf,
            'series' => $this->series,
            'type' => $this->type,
            'valid' => $this->isValid(),
            'code' => $this->code,
            'reason' => $this->reason,
        ];
    }
}
