<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\DataTransferObjects;

use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoViajeEnum;

class TravelQuoteData
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
        $this->tripType ??= TipoViajeEnum::UN_VIAJE->value;
    }

    public function toArray(): array
    {
        return [
            'lead_id' => $this->leadId,
            'origin_country' => $this->originCountry,
            'destination' => $this->destination,
            'start_date' => $this->startDate->toDateString(),
            'end_date' => $this->endDate->toDateString(),
            'passenger_count' => $this->passengerCount,
            'passenger_ages' => $this->passengerAges,
            'trip_type' => $this->tripType,
            'agreement_id' => $this->agreementId,
            'brochure' => $this->brochure,
            'family_pack' => $this->familyPack,
            'quote_count' => $this->quoteCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            originCountry: $data['origin_country'],
            destination: $data['destination'],
            startDate: Carbon::parse($data['start_date']),
            endDate: Carbon::parse($data['end_date']),
            passengerCount: (int) $data['passenger_count'],
            passengerAges: $data['passenger_ages'],
            leadId: $data['lead_id'] ?? null,
            tripType: $data['trip_type'] ?? null,
            agreementId: $data['agreement_id'] ?? null,
            brochure: $data['brochure'] ?? 'N',
            familyPack: $data['family_pack'] ?? null,
            quoteCount: (int) ($data['quote_count'] ?? 1)
        );
    }
}
