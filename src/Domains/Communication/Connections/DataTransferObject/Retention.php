<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

use Carbon\CarbonInterval;

/**
 * How long a provider can keep reporting on a message after its last event. A delivered message is
 * not a finished one — a read receipt or a complaint can follow — so a vendor registration is only
 * safe to drop once every delivery behind it is past this window or has seen a terminal event.
 */
final readonly class Retention
{
    /**
     * @param list<string> $terminalEvents provider events after which no other can arrive
     */
    public function __construct(
        public CarbonInterval $window,
        public array $terminalEvents = []
    ) {
    }
}
