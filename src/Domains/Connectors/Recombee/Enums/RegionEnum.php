<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Enums;

/**
 * The regions the Recombee SDK maps to a base URI. Anything else reaches
 * Client::getRegionalBaseUri() as an unknown array key, which PHP raises as a
 * warning (converted to an ErrorException by Laravel) before the SDK gets to
 * throw its own "Region X is unknown".
 */
enum RegionEnum: string
{
    case AP_SE = 'ap-se';
    case CA_EAST = 'ca-east';
    case EU_WEST = 'eu-west';
    case US_WEST = 'us-west';

    public static function fromConfiguration(mixed $region): self
    {
        if (! is_string($region)) {
            return self::CA_EAST;
        }

        return self::tryFrom(strtolower(trim($region))) ?? self::CA_EAST;
    }
}
