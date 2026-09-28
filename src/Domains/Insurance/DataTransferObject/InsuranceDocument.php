<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsuranceDocumentTypeEnum;

class InsuranceDocument
{
    public function __construct(
        public readonly InsuranceDocumentTypeEnum $type,
        public readonly string $path,
    ) {
    }
}
