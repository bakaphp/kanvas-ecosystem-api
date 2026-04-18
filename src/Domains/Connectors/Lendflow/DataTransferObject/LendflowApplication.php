<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\DataTransferObject;

use Kanvas\Guild\Deals\Models\Deal;

class LendflowApplication
{
    public function __construct(
        public readonly Deal $deal,
        public readonly string $workflowTemplateId,
        public readonly array $payload,
    ) {
    }
}
