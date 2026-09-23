<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use League\Csv\Info;
use League\Csv\Reader;
use RuntimeException;

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
        $reader->setDelimiter(self::detectDelimiterFromHeader($path) ?? self::detectDelimiter($reader, $default));

        return $reader;
    }

    /**
     * The header row decides, because only it is guaranteed to be column names. Scoring a sample of
     * data rows instead lets a delimiter that appears *inside* a cell outvote the real one — a dealer
     * feed's `Photo Url List` holds pipe-separated URLs, enough to make a comma file parse as one
     * column. Null when the header holds no candidate at all.
     */
    private static function detectDelimiterFromHeader(string $path): ?string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $header = fgets($handle);
        fclose($handle);

        if ($header === false) {
            return null;
        }

        $counts = [];
        foreach (self::CANDIDATE_DELIMITERS as $candidate) {
            $counts[$candidate] = self::countOutsideQuotes($header, $candidate);
        }

        $best = max($counts);
        if ($best === 0) {
            return null;
        }

        // The default wins a tie, so "a,b;c" stays a comma file.
        return ($counts[self::DEFAULT_DELIMITER] ?? 0) === $best
            ? self::DEFAULT_DELIMITER
            : (string) array_search($best, $counts, true);
    }

    /**
     * A quoted column name may legitimately contain the delimiter ("Dealer, Inc").
     */
    private static function countOutsideQuotes(string $line, string $needle): int
    {
        $count = 0;
        $inQuotes = false;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $line[$i] === $needle) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Some dealer-feed exporters escape a quote as `\"` right before the delimiter or line end,
     * which League\Csv reads as an unterminated field that swallows the rest of the file. Rewrites
     * the file in place, streaming, so a large feed is never loaded whole.
     */
    public static function repairBackslashEscapedQuotes(string $path): void
    {
        $in = fopen($path, 'r');
        $repairedPath = $path . '.repaired';
        $out = fopen($repairedPath, 'w');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not open CSV for repair: ' . $path);
        }

        while (($line = fgets($in)) !== false) {
            $line = str_replace('\\",', '",', $line);
            fwrite($out, (string) preg_replace('/\\\\"(\r?\n)?$/', '"$1', $line));
        }

        fclose($in);
        fclose($out);
        rename($repairedPath, $path);
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
