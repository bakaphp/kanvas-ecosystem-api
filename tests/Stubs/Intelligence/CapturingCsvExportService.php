<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Keeps the real CSV assembly exercised while swapping the upload, which needs live cloud
 * credentials, for an in-memory capture the test can assert the file's bytes against.
 */
final class CapturingCsvExportService extends CsvExportService
{
    public string $content = '';
    public string $filename = '';

    #[Override]
    protected function store(
        Apps $app,
        Companies $company,
        Users $user,
        string $filename,
        string $content,
    ): string {
        $this->content = $content;
        $this->filename = $filename;

        return 'https://fake.test/' . $filename;
    }
}
