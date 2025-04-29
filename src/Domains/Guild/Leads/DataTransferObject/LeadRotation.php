<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

class LeadRotation extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public string $name,
        #[MapInputName(SnakeCaseMapper::class)]
        #[MapOutputName(SnakeCaseMapper::class)]
        public ?string $leadsRotationsEmail  = null,
        public int $hits = 0,
        public array $agents = []
    ) {
    }
}
