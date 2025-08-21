<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\DataTransferObjects;

use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Enums\TripTypeEnum;
use Spatie\LaravelData\Data;

class TravelQuoteData extends Data
{
    public function __construct(
        public string $originCountry,
        public string $destination,
        public Carbon $startDate,
        public Carbon $endDate,
        public int $passengerCount,
        public array $passengerAges,
        public ?string $leadId = null,
        public ?string $tripType = null,
        public ?string $agreementId = null,
        public ?string $brochure = 'N',
        public ?string $familyPack = null,
        public int $quoteCount = 1
    ) {
        $this->tripType ??= TripTypeEnum::SINGLE_TRIP->value;
    }
}
