<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\DataTransferObject;

use Kanvas\Event\Passes\Enums\PassScanResultEnum;

final readonly class PassScanOutcome
{
    public function __construct(
        public PassScanResultEnum $result,
        public ?array $booking = null,
    ) {
    }

    public function resultValue(): string
    {
        return $this->result->value;
    }

    public function admitted(): bool
    {
        return $this->result === PassScanResultEnum::ADMITTED;
    }
}
