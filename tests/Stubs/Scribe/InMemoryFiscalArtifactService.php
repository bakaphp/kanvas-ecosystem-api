<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe;

use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalArtifactService;

/**
 * Keeps attempt artifacts in memory so e-CF tests don't upload two files to S3 per send. The real service
 * is exercised by FiscalDocumentsTest, which constructs it explicitly.
 */
class InMemoryFiscalArtifactService extends FiscalArtifactService
{
    /** @var array<int, string> */
    public static array $stored = [];

    public function store(FiscalDocument $fiscal, string $contents, string $fileName): array
    {
        self::$stored[] = $contents;

        return ['file_id' => count(self::$stored), 'sha256' => hash('sha256', $contents)];
    }
}
