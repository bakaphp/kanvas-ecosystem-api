<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Documents;

use Baka\Support\Str;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\Support\TemporaryFile;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

class ExcelDocument
{
    public const int MAX_ROWS = 500;
    public const int MAX_COLUMNS = 50;

    /**
     * Zip entries PhpSpreadsheet reads and writes back. Anything else in the package (pivot tables,
     * threaded comments, connections, macros, customXml...) would be silently dropped on save.
     * calcChain.xml is not written back either, but it is only a calculation cache Excel rebuilds.
     */
    private const array EDITABLE_PARTS = [
        '#^\[Content_Types\]\.xml$#',
        '#^_rels/#',
        '#^docProps/#',
        '#^xl/workbook\.xml$#',
        '#^xl/_rels/#',
        '#^xl/worksheets/#',
        '#^xl/styles\.xml$#',
        '#^xl/sharedStrings\.xml$#',
        '#^xl/theme/#',
        '#^xl/calcChain\.xml$#',
        '#^xl/drawings/#',
        '#^xl/charts/#',
        '#^xl/media/#',
        '#^xl/comments[0-9]*\.xml$#',
        '#^xl/printerSettings/#',
        '#^xl/tables/#',
        '#^xl/metadata\.xml$#',
    ];

    /**
     * @return array{sheet: string, sheets: list<string>, range: string, rows: list<list<mixed>>, truncated: bool}
     */
    public static function read(string $bytes, ?string $sheet = null, ?string $range = null): array
    {
        $spreadsheet = self::load($bytes);

        try {
            $worksheet = self::resolveSheet($spreadsheet, $sheet);
            $range ??= $worksheet->calculateWorksheetDataDimension();

            try {
                [$start, $end] = Coordinate::rangeBoundaries($range);
            } catch (Throwable) {
                throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Invalid range: ' . $range);
            }

            // One row past the cap is enough to know whether the result was truncated.
            $limitedEndRow = min($end[1], $start[1] + self::MAX_ROWS);
            $limitedEndColumn = min($end[0], $start[0] + self::MAX_COLUMNS - 1);
            $limited = Coordinate::stringFromColumnIndex($start[0]) . $start[1]
                . ':' . Coordinate::stringFromColumnIndex($limitedEndColumn) . $limitedEndRow;

            try {
                $rows = $worksheet->rangeToArray($limited, null, true, false, false);
            } catch (Throwable $e) {
                throw new OneDriveException(
                    OneDriveErrorReasonEnum::REQUEST_FAILED,
                    'Could not calculate the sheet: ' . $e->getMessage(),
                    previous: $e
                );
            }

            $truncated = count($rows) > self::MAX_ROWS || $end[0] > $limitedEndColumn;

            return [
                'sheet' => $worksheet->getTitle(),
                'sheets' => $spreadsheet->getSheetNames(),
                'range' => $range,
                'rows' => array_slice($rows, 0, self::MAX_ROWS),
                'truncated' => $truncated,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param array<string, scalar|null> $cells
     * @param list<list<scalar|null>> $appendRows
     */
    public static function write(
        string $bytes,
        ?string $sheet,
        array $cells,
        array $appendRows
    ): string {
        self::assertEditable($bytes);
        $spreadsheet = self::load($bytes);

        try {
            $worksheet = self::resolveSheet($spreadsheet, $sheet);

            foreach ($cells as $reference => $value) {
                $reference = (string) $reference;
                if (! self::isCellReference($reference)) {
                    throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Invalid cell reference: ' . $reference);
                }
                self::setValue($worksheet, strtoupper($reference), $value);
            }

            $nextRow = $worksheet->getCellCollection()->getCoordinates() === [] ? 1 : $worksheet->getHighestDataRow() + 1;
            foreach ($appendRows as $row) {
                self::writeRow($worksheet, $nextRow++, array_values($row));
            }

            return self::save($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param list<list<scalar|null>> $rows
     */
    public static function create(array $rows): string
    {
        $spreadsheet = new Spreadsheet();

        try {
            foreach (array_values($rows) as $index => $row) {
                self::writeRow($spreadsheet->getActiveSheet(), $index + 1, array_values($row));
            }

            return self::save($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param list<scalar|null> $row
     */
    private static function writeRow(Worksheet $worksheet, int $rowNumber, array $row): void
    {
        foreach ($row as $column => $value) {
            self::setValue($worksheet, Coordinate::stringFromColumnIndex($column + 1) . $rowNumber, $value);
        }
    }

    private static function setValue(Worksheet $worksheet, string $coordinate, mixed $value): void
    {
        if ($value !== null && ! is_scalar($value)) {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Invalid cell value for ' . $coordinate);
        }

        if (is_string($value)) {
            $value = Str::stripInvalidXmlChars($value);
        }

        $worksheet->setCellValue($coordinate, $value);
    }

    private static function isCellReference(string $reference): bool
    {
        if (preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/i', $reference, $matches) !== 1) {
            return false;
        }

        return Coordinate::columnIndexFromString(strtoupper($matches[1])) <= 16384 && (int) $matches[2] <= 1048576;
    }

    private static function resolveSheet(Spreadsheet $spreadsheet, ?string $sheet): Worksheet
    {
        if ($sheet === null) {
            return $spreadsheet->getSheet(0);
        }

        $worksheet = $spreadsheet->getSheetByName($sheet);
        if ($worksheet === null) {
            throw new OneDriveException(OneDriveErrorReasonEnum::NOT_FOUND, 'Sheet not found: ' . $sheet);
        }

        return $worksheet;
    }

    private static function assertEditable(string $bytes): void
    {
        TemporaryFile::withBytes($bytes, function (string $path) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return;
            }

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = (string) $zip->getNameIndex($index);
                    if (! str_ends_with($name, '/') && ! self::isEditablePart($name)) {
                        throw new OneDriveException(
                            OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
                            'This workbook contains features that would be lost if edited (' . $name . ')'
                        );
                    }
                }
            } finally {
                $zip->close();
            }
        });
    }

    private static function isEditablePart(string $name): bool
    {
        foreach (self::EDITABLE_PARTS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function load(string $bytes): Spreadsheet
    {
        return TemporaryFile::withBytes($bytes, function (string $path) {
            try {
                $reader = IOFactory::createReader('Xlsx');
                $reader->setIncludeCharts(true);

                return $reader->load($path);
            } catch (Throwable) {
                throw new OneDriveException(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, 'The file is not an Excel (.xlsx) workbook.');
            }
        });
    }

    private static function save(Spreadsheet $spreadsheet): string
    {
        return TemporaryFile::withBytes('', function (string $path) use ($spreadsheet) {
            try {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->setIncludeCharts(true);
                $writer->save($path);
            } catch (Throwable $e) {
                throw new OneDriveException(
                    OneDriveErrorReasonEnum::REQUEST_FAILED,
                    'Could not save the workbook: ' . $e->getMessage(),
                    previous: $e
                );
            }

            return TemporaryFile::contents($path);
        });
    }
}
