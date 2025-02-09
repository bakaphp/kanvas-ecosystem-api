<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\DataTransferObject;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
class ESimStatus extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $callTypeGroup,
        public readonly int $initialQuantity,
        public readonly int $remainingQuantity,
        public readonly string $assignmentDateTime,
        public readonly string $assignmentReference,
        public readonly string $bundleState,
        public readonly bool $unlimited,
        public readonly ?string $expirationDate = null,
        public readonly ?string $phoneNumber = null,
        public readonly ?string $imei = null,
        public readonly ?string $esimStatus = null,
        public readonly ?string $message = null,
        public readonly ?string $installedDate = null
    ) {
    }
}
