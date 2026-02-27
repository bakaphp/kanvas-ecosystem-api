<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Spatie\LaravelData\Data;

class UpdateEngagement extends Data
{
    public function __construct(
        public readonly array $data,
        public readonly ?ActionStatusEnum $status = null,
        public readonly ?string $description = null,
        public readonly ?string $via = null,
    ) {
    }
}
