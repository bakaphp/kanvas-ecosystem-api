<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;

/**
 * Turns a set of rows into a downloadable CSV and hands back a file reference for the agent
 * to give the user — never tens of thousands of inline rows. Format is Excel-friendly per the
 * Command Center spec: UTF-8 with BOM, CRLF line endings, every cell quoted ("" escaped).
 *
 * Resolved from the container so the upload side (which needs live cloud credentials) can be
 * swapped in tests while the pure CSV assembly stays exercised.
 */
class CsvExportService
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param list<string>              $headers
     * @param list<array<int, mixed>>   $rows
     *
     * @return array{file_url: string, row_count: int, companies: list<string>, expires_at: ?string}
     */
    public function export(
        Apps $app,
        Companies $company,
        Users $user,
        string $filenamePrefix,
        array $headers,
        array $rows,
    ): array {
        $filename = $filenamePrefix . '-' . now()->format('Y-m-d') . '.csv';
        $url = $this->store($app, $company, $user, $filename, $this->buildCsv($headers, $rows));

        return [
            'file_url' => $url,
            'row_count' => count($rows),
            'companies' => [$company->name],
            'expires_at' => null,
        ];
    }

    /**
     * @param list<string>            $headers
     * @param list<array<int, mixed>> $rows
     */
    public function buildCsv(array $headers, array $rows): string
    {
        $lines = [$this->line($headers)];

        foreach ($rows as $row) {
            $lines[] = $this->line($row);
        }

        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }

    protected function store(
        Apps $app,
        Companies $company,
        Users $user,
        string $filename,
        string $content,
    ): string {
        $path = (string) tempnam(sys_get_temp_dir(), 'kanvas_csv_');
        file_put_contents($path, $content);

        try {
            $filesystem = new FilesystemServices($app, $company)->upload(
                new UploadedFile($path, $filename, 'text/csv', null, true),
                $user,
            );

            return $filesystem->url;
        } finally {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<int, mixed> $cells
     */
    private function line(array $cells): string
    {
        return implode(',', array_map(
            fn (mixed $cell): string => '"' . str_replace('"', '""', (string) $cell) . '"',
            $cells,
        ));
    }
}
