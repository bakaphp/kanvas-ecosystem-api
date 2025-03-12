<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\DataTransferObject;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
class ESim extends Data
{
    public function __construct(
        public readonly string $lpaCode,
        public readonly string $iccid,
        public readonly string $status,
        public readonly int $quantity,
        public readonly float $pricePerUnit,
        public readonly string $type,
        public readonly string $plan,
        public readonly string $smdpAddress,
        public readonly string $matchingId,
        public readonly ?int $firstInstalledDatetime,
        public readonly string $orderReference,
        public readonly string $qrCode,
        public readonly ESimStatus $esimStatus,
        public readonly ?string $label = null
    ) {
    }
}
