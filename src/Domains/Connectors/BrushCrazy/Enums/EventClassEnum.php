<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * The booking model, derived rather than stored. BrushCrazy's `type` column is a free varchar and
 * carries the theme of the session, not how it is sold — that lives in the drop-in / private /
 * fundraiser flags, which is what staff actually operate on.
 */
enum EventClassEnum: string
{
    case DROP_IN = 'Drop-In';
    case PRIVATE = 'Private';
    case FUNDRAISER = 'Fundraiser';
    case PUBLIC = 'Public';
}
