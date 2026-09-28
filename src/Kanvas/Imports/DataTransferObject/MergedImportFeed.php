<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Spatie\LaravelData\Data;

class MergedImportFeed extends Data
{
    /**
     * @param list<string> $skus every SKU the merged feed will import, for "unpublish what's missing"
     */
    public function __construct(
        public readonly string $path,
        public readonly int $rows,
        public readonly int $skippedRows,
        public readonly array $skus,
    ) {
    }
}
