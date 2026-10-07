<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Locations\Models\Countries;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class ShipmentRequest extends Data
{
    public function __construct(
        public readonly Countries $destinationCountry,
        #[DataCollectionOf(Parcel::class)]
        public readonly DataCollection $parcels,
        public readonly ?string $destinationCity = null,
        public readonly ?string $destinationPostalCode = null,
        public readonly ?Countries $originCountry = null,
        public readonly ?string $originCity = null,
        public readonly ?string $originPostalCode = null,
        public readonly ?Carbon $shipDate = null,
    ) {
    }

    public function totalWeight(): float
    {
        return round((float) $this->parcels->toCollection()->sum(fn (Parcel $parcel) => $parcel->weight), 3);
    }
}
