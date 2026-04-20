<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class InscriptionsReport extends Data
{
    /**
     * @param  DataCollection<array-key, InscriptionWeekData>  $weeks
     */
    public function __construct(
        public int $event_version_id,
        public string $event_name,
        public int $goal,
        #[DataCollectionOf(InscriptionWeekData::class)]
        public DataCollection $weeks,
    ) {
    }
}
