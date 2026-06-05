<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class InscriptionTrack extends Data
{
    public function __construct(
        public string $type,
        public string $slug,
        public int $count,
    ) {
    }
}
