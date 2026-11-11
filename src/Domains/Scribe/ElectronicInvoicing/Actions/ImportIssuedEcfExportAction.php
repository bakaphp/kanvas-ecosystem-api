<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfImportResult;
use Kanvas\Scribe\ElectronicInvoicing\Services\IssuedEcfExportParserService;
use Kanvas\Users\Models\Users;

/**
 * Feeds a provider's issued-documents export, row by row, to ImportIssuedEcfRowAction and gathers the report a
 * person reads: what was created, what the authority had rejected, what was already here, what needs a human
 * and what the file itself could not say.
 */
class ImportIssuedEcfExportAction
{
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $contents,
        public readonly Users $user,
        public readonly bool $dryRun = false,
        private readonly IssuedEcfExportParserService $parser = new IssuedEcfExportParserService(),
    ) {
    }

    public function execute(): IssuedEcfImportResult
    {
        if (! $this->parser->matches($this->contents)) {
            return new IssuedEcfImportResult(
                IssuedEcfImportResult::UNKNOWN_LAYOUT,
                $this->dryRun,
                peek: $this->parser->peek($this->contents),
            );
        }

        ['rows' => $rows, 'unreadable' => $unreadable] = $this->parser->parse($this->contents);

        if ($rows === []) {
            return new IssuedEcfImportResult(IssuedEcfImportResult::NOTHING_TO_IMPORT, $this->dryRun, unreadable: $unreadable);
        }

        $results = [];

        foreach ($rows as $row) {
            $results[] = new ImportIssuedEcfRowAction(
                app: $this->app,
                company: $this->company,
                row: $row,
                user: $this->user,
                dryRun: $this->dryRun,
            )->execute();
        }

        return new IssuedEcfImportResult(
            IssuedEcfImportResult::IMPORTED,
            $this->dryRun,
            $results,
            $unreadable,
        );
    }
}
