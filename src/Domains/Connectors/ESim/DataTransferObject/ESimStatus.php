<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\DataTransferObject;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
##[MapOutputName(SnakeCaseMapper::class)]
class ESimStatus extends Data
{
    public function __construct(
        public readonly string $id,
        #[MapOutputName('call_type_group')]
        public readonly string $callTypeGroup,
        public readonly int|float $initialQuantity,
        public readonly int|float $remainingQuantity,
        #[MapOutputName('assignment_date_time')]
        public readonly string $assignmentDateTime,
        #[MapOutputName('assignment_reference')]
        public readonly string $assignmentReference,
        public readonly string $bundleState,
        public readonly bool $unlimited,
        #[MapOutputName('expiration_date')]
        public readonly ?string $expirationDate = null,
        #[MapOutputName('phone_number')]
        public readonly ?string $phoneNumber = null,
        public readonly ?string $imei = null,
        #[MapOutputName('esim_status')]
        public readonly ?string $esimStatus = null, //enable if you want it to show @todo move to enums
        public readonly ?string $message = null,
        #[MapOutputName('installed_date')]
        public readonly ?string $installedDate = null,
        public readonly ?string $activationDate = null,
        public readonly ?string $spentMessage = null,
    ) {
    }
}
