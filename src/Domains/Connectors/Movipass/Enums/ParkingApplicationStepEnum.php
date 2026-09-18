<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum ParkingApplicationStepEnum: string
{
    case IDENTIFICATION = 'identification';
    case CHARACTERISTICS = 'characteristics';
    case LOCATION = 'location';
    case CAPACITY = 'capacity';
    case PHOTOS = 'photos';
    case OPERATIONS = 'operations';
    case RATES = 'rates';
    case BANKING = 'banking';
    case CONTRACT = 'contract';

    public function order(): int
    {
        return match ($this) {
            self::IDENTIFICATION => 1,
            self::CHARACTERISTICS => 2,
            self::LOCATION => 3,
            self::CAPACITY => 4,
            self::PHOTOS => 5,
            self::OPERATIONS => 6,
            self::RATES => 7,
            self::BANKING => 8,
            self::CONTRACT => 9,
        };
    }

    public function fields(): array
    {
        return array_values(array_filter(
            ParkingApplicationFieldEnum::cases(),
            fn (ParkingApplicationFieldEnum $field): bool => $field->step() === $this,
        ));
    }
}
