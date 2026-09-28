<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Spatie\LaravelData\Data;

class ImportRunResult extends Data
{
    /**
     * @param list<array{pattern: string, matched: string|null, downloaded: bool}> $files
     * @param list<array<string, mixed>> $sample dry run only: the first records exactly as the importer receives them
     */
    public function __construct(
        public readonly ImportRunStatusEnum $status,
        public readonly string $message,
        public readonly array $files = [],
        public readonly int $rows = 0,
        public readonly int $skippedRows = 0,
        public readonly array $sample = [],
        public readonly ?FilesystemImports $filesystemImport = null,
    ) {
    }
}
