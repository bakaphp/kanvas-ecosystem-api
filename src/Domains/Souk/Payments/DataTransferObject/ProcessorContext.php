<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class ProcessorContext
{
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly array $params = [],
    ) {
    }
}
