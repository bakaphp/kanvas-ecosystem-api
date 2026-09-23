<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Baka\Support\Str;
use Kanvas\Filesystem\Services\CsvReaderService;
use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Kanvas\Imports\DataTransferObject\MergedImportFeed;
use RuntimeException;

/**
 * Turns several downloaded CSVs into the one CSV an import run uploads. The product importer
 * groups rows by the mapper's `handler` and fails the whole run on an out-of-order handler, so
 * rows are written grouped, and a handler already taken from an earlier file is dropped from
 * later ones (first file wins — e.g. a car listed in two rooftops' files).
 */
class MergeImportFilesAction
{
    /**
     * @param list<array{path: string, filter: array{column: string, in: list<string>}|null}> $files
     */
    public function __construct(
        private readonly array $files,
        private readonly array $mapping,
        private readonly string $outputPath,
    ) {
    }

    public function execute(): MergedImportFeed
    {
        $header = [];
        $groups = [];
        $skus = [];
        $skipped = 0;
        $groupedByHandler = array_key_exists('handler', $this->mapping);

        foreach ($this->files as $fileIndex => $file) {
            CsvReaderService::repairBackslashEscapedQuotes($file['path']);
            $reader = CsvReaderService::fromPath($file['path']);
            $reader->setHeaderOffset(0);
            $columns = $this->cleanHeader($reader->getHeader());
            $header = array_values(array_unique(array_merge($header, $columns)));

            $filter = $this->allowedValues($file['filter']);
            $takenInThisFile = [];
            foreach ($reader->getRecords($columns) as $rowIndex => $row) {
                if ($filter !== null && ! isset($filter['in'][Str::lowerTrim((string) ($row[$filter['column']] ?? ''))])) {
                    continue;
                }

                $handler = $groupedByHandler
                    ? FilesystemRowMapper::resolveText($this->mapping['handler'], $row)
                    : $fileIndex . ':' . $rowIndex;

                if ($handler === null || (isset($groups[$handler]) && ! isset($takenInThisFile[$handler]))) {
                    $skipped++;

                    continue;
                }

                $takenInThisFile[$handler] = true;
                $groups[$handler][] = $row;

                $sku = FilesystemRowMapper::resolveText($this->mapping['sku'] ?? null, $row);
                if ($sku !== null) {
                    $skus[$sku] = true;
                }
            }
        }

        $rows = $this->write($header, $groups);

        return new MergedImportFeed(
            path: $this->outputPath,
            rows: $rows,
            skippedRows: $skipped,
            skus: array_map('strval', array_keys($skus)),
        );
    }

    /**
     * @param list<string> $header
     * @param array<string, list<array<string, mixed>>> $groups
     */
    private function write(array $header, array $groups): int
    {
        $out = fopen($this->outputPath, 'w');
        if ($out === false) {
            throw new RuntimeException('Could not write merged import file: ' . $this->outputPath);
        }

        fputcsv($out, $header, ',', '"', '');

        $rows = 0;
        foreach ($groups as $group) {
            foreach ($group as $row) {
                fputcsv(
                    $out,
                    array_map(fn (string $column) => (string) ($row[$column] ?? ''), $header),
                    ',',
                    '"',
                    ''
                );
                $rows++;
            }
        }

        fclose($out);

        return $rows;
    }

    /**
     * @param array{column: string, in: list<string>}|null $filter
     *
     * @return array{column: string, in: array<string, true>}|null the allowed values as a lookup set
     */
    private function allowedValues(?array $filter): ?array
    {
        if ($filter === null) {
            return null;
        }

        return [
            'column' => $filter['column'],
            'in' => array_fill_keys(array_map(fn ($allowed) => Str::lowerTrim((string) $allowed), $filter['in']), true),
        ];
    }

    /**
     * @return list<string>
     */
    private function cleanHeader(array $columns): array
    {
        return array_values(array_map(fn ($column) => trim(Str::stripBom((string) $column)), $columns));
    }
}
