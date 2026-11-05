<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

use Carbon\CarbonInterval;
use Spatie\LaravelData\Data;

/**
 * How long a provider can keep reporting on a message after its last event. A delivered message is
 * not a finished one — a read receipt or a complaint can follow — so a vendor registration is only
 * safe to drop once every delivery behind it is past this window or has seen a terminal event.
 */
class Retention extends Data
{
    /**
     * @param list<string> $terminalEvents provider events after which no other can arrive
     */
    public function __construct(
        public readonly CarbonInterval $window,
        public readonly array $terminalEvents = []
    ) {
    }
}
