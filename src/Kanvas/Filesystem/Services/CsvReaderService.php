<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use League\Csv\Info;
use League\Csv\Reader;

/**
 * Opens a CSV with its real field delimiter instead of assuming a comma.
 *
 * Excel exports semicolon-delimited CSV by default in most of Europe and
 * Latin America. League\Csv assumes `,`, so such a file parses as a single
 * column: the mapper UI shows one bogus header, every mapped column resolves
 * to null, and the import silently does nothing.
 *
 * Detection is done on the file's shape (which candidate yields a consistent
 * column count across a sample of rows), not by looking for a character — so
 * a comma file whose cells contain semicolons is still read as comma.
 */
final class CsvReaderService
{
    public const DEFAULT_DELIMITER = ',';

    private const CANDIDATE_DELIMITERS = [',', ';', "\t", '|'];
    private const SAMPLE_ROWS = 10;

    public static function fromPath(string $path, string $default = self::DEFAULT_DELIMITER): Reader
    {
        $reader = Reader::createFromPath($path, 'r');
        $reader->setDelimiter(self::detectDelimiter($reader, $default));

        return $reader;
    }

    public static function detectDelimiter(Reader $reader, string $default = self::DEFAULT_DELIMITER): string
    {
        $stats = Info::getDelimiterStats($reader, self::CANDIDATE_DELIMITERS, self::SAMPLE_ROWS);

        if ($stats === []) {
            return $default;
        }

        $best = max($stats);

        // A single-column file scores zero everywhere; so does an empty one.
        if ($best <= 0) {
            return $default;
        }

        // The default wins ties, so a comma file can never be re-read as
        // something else by a tie-break that happens to order differently.
        if (($stats[$default] ?? 0) === $best) {
            return $default;
        }

        $detected = array_search($best, $stats, true);

        return is_string($detected) ? $detected : $default;
    }
}
