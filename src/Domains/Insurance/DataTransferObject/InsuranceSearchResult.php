<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class InsuranceSearchResult
{
    public function __construct(
        public readonly array $entries,
        public readonly array $failures,
    ) {
    }
}
