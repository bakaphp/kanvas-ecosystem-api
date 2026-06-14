<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<ReportLineItem> $lines
 */
class ReportSection extends Data
{
    public function __construct(
        public readonly string $title,
        /** @var DataCollection<ReportLineItem> */
        public readonly DataCollection $lines,
        public readonly float $total,
    ) {
    }
}
