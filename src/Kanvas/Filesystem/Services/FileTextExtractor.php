<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Turns an uploaded file into plain text, for knowledge indexing and for the `read_file` tool.
 *
 * Pure-PHP throughout (pdfparser, PhpSpreadsheet, ZipArchive) so a hostile upload reaches no shell
 * or ImageMagick delegate. DOCX is unzipped straight out of `word/document.xml` — phpoffice/phpword
 * is a large dependency for one XML file and is not installed.
 *
 * Unsupported or unreadable returns '': a knowledge sweep must not die on one bad file.
 */
final class FileTextExtractor
{
    private const array TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'log'];

    private const array CSV_EXTENSIONS = ['csv', 'tsv'];

    private const array JSON_EXTENSIONS = ['json'];

    private const array PDF_EXTENSIONS = ['pdf'];

    private const array WORD_EXTENSIONS = ['docx'];

    private const array SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

    /** Past this a spreadsheet is a data feed, not something an agent reads into a prompt. */
    private const int MAX_SPREADSHEET_ROWS = 2000;

    /**
     * @return list<string>
     */
    public static function supportedExtensions(): array
    {
        return [
            ...self::TEXT_EXTENSIONS,
            ...self::CSV_EXTENSIONS,
            ...self::JSON_EXTENSIONS,
            ...self::PDF_EXTENSIONS,
            ...self::WORD_EXTENSIONS,
            ...self::SPREADSHEET_EXTENSIONS,
        ];
    }

    public function supports(Filesystem $file): bool
    {
        return in_array($this->extension($file), self::supportedExtensions(), true);
    }

    public function extract(Filesystem $file): string
    {
        $extension = $this->extension($file);

        if (! in_array($extension, self::supportedExtensions(), true)) {
            return '';
        }

        return $this->extractFrom(SafeUrlFetcher::fetch($file->url), $extension);
    }

    /** Split from {@see extract()} so the per-format parsing is reachable without a network fetch. */
    public function extractFrom(string $bytes, string $extension): string
    {
        return match (true) {
            in_array($extension, self::PDF_EXTENSIONS, true) => $this->extractPdf($bytes),
            in_array($extension, self::WORD_EXTENSIONS, true) => $this->extractDocx($bytes),
            in_array($extension, self::SPREADSHEET_EXTENSIONS, true) => $this->extractSpreadsheet($bytes, $extension),
            in_array($extension, self::JSON_EXTENSIONS, true) => $this->extractJson($bytes),
            default => $this->normalize($bytes),
        };
    }

    private function extractPdf(string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF')) {
            return '';
        }

        return $this->normalize(new Parser()->parseContent($bytes)->getText());
    }

    /**
     * A .docx is a zip of XML. `w:p` is a paragraph and `w:tab` a cell break, so both are turned into
     * whitespace before the tags come out — otherwise every paragraph runs into the next one and a
     * table reads as one unbroken string.
     */
    private function extractDocx(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $bytes);

            $zip = new ZipArchive();

            if ($zip->open($path) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml) || $xml === '') {
                return '';
            }

            $spaced = preg_replace(
                ['#</w:p>#', '#<w:tab[^>]*/>#'],
                ["\n", "\t"],
                $xml,
            );

            return $this->normalize(html_entity_decode(strip_tags((string) $spaced), ENT_QUOTES | ENT_XML1));
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($path);
        }
    }

    /**
     * Rendered as TSV rather than a JSON grid: it is the densest readable form per token, and every
     * model already reads a tab-separated table without being told what it is.
     */
    private function extractSpreadsheet(string $bytes, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');

        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $bytes);

            // NOT setReadDataOnly: that drops the number formats, and without them a date cell is
            // read as its raw serial (46265, not 2026-08-31) whatever toArray is asked to format.
            // The row cap below is what bounds the cost of parsing styles.
            $reader = IOFactory::createReader($extension === 'xls' ? 'Xls' : 'Xlsx');
            $book = $reader->load($path);

            return $this->normalize($this->renderSpreadsheet($book));
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($path);
        }
    }

    private function renderSpreadsheet(Spreadsheet $book): string
    {
        $out = [];
        $rows = 0;

        foreach ($book->getAllSheets() as $sheet) {
            $out[] = '# Sheet: ' . $sheet->getTitle();

            // formatData renders dates and currency as the sheet displays them; without it a date
            // column arrives as its Excel serial (46265, not 2026-08-31) and the model reads noise.
            foreach ($sheet->toArray(formatData: true) as $row) {
                if ($rows++ >= self::MAX_SPREADSHEET_ROWS) {
                    $out[] = sprintf(
                        '[truncated at %d rows — read the file directly if you need the rest]',
                        self::MAX_SPREADSHEET_ROWS,
                    );

                    return implode("\n", $out);
                }

                $out[] = implode("\t", array_map(static fn ($cell): string => trim((string) $cell), $row));
            }
        }

        return implode("\n", $out);
    }

    /** Re-encoded so a minified payload arrives readable; invalid JSON is still worth returning raw. */
    private function extractJson(string $bytes): string
    {
        $decoded = json_decode($this->normalize($bytes), true);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->normalize($bytes);
    }

    private function normalize(string $bytes): string
    {
        return trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $bytes));
    }

    private function extension(Filesystem $file): string
    {
        return strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
    }
}
