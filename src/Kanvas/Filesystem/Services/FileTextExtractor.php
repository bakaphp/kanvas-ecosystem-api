<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
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

    /**
     * Plain text wearing a format's name. They need no parser — `extractFrom()` returns them as-is —
     * but `extract()` and `supports()` gate on the extension, so one left off this list is refused as
     * "not a readable document type" even though it is readable.
     */
    private const array CODE_EXTENSIONS = [
        'graphql',
        'ini',
        'js',
        'jsx',
        'ndjson',
        'php',
        'py',
        'rb',
        'sh',
        'sql',
        'toml',
        'ts',
        'tsx',
        'xml',
        'yaml',
        'yml',
    ];

    /**
     * `application/*` labels finfo hands back for what is really plain text — a well-formed `.json`
     * is reported as `application/json`, a shell script as `application/x-sh`.
     */
    private const array TEXTUAL_MIME_TYPES = [
        'application/ecmascript',
        'application/graphql',
        'application/javascript',
        'application/sql',
        'application/toml',
        'application/x-httpd-php',
        'application/x-javascript',
        'application/x-perl',
        'application/x-python',
        'application/x-ruby',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-sql',
        'application/x-yaml',
        'application/xml',
        'application/yaml',
    ];

    private const array CSV_EXTENSIONS = ['csv', 'tsv'];

    private const array JSON_EXTENSIONS = ['json'];

    private const array PDF_EXTENSIONS = ['pdf'];

    private const array WORD_EXTENSIONS = ['docx'];

    private const array SPREADSHEET_EXTENSIONS = ['xlsx', 'xls', 'ods'];

    /** Past this a spreadsheet is a data feed, not something an agent reads into a prompt. */
    private const int MAX_SPREADSHEET_ROWS = 2000;

    /**
     * Read at most this much of any one member of an uploaded archive. A DOCX/XLSX is a zip, and a
     * zip's members decompress to an unbounded size — a small upload can expand by three orders of
     * magnitude, and the text pipeline then copies whatever came out several times over.
     * ZipArchive::getFromName() streams when given a length, so this bounds the read itself rather
     * than trimming afterwards, which would be too late to matter.
     */
    public const int MAX_ARCHIVE_MEMBER_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly int $maxArchiveMemberBytes = self::MAX_ARCHIVE_MEMBER_BYTES,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function supportedExtensions(): array
    {
        return [
            ...self::TEXT_EXTENSIONS,
            ...self::CODE_EXTENSIONS,
            ...self::CSV_EXTENSIONS,
            ...self::JSON_EXTENSIONS,
            ...self::PDF_EXTENSIONS,
            ...self::WORD_EXTENSIONS,
            ...self::SPREADSHEET_EXTENSIONS,
        ];
    }

    /**
     * The parser a sniffed MIME type routes to, expressed as the extension `extractFrom()` keys on,
     * or null when nothing here can read it. It is what lets a caller holding only bytes — an agent
     * attachment has a URL but no filename — reach the same parsers as a caller holding a filename.
     *
     * Not to be confused with MediaTypeEnum::extensionForMime(), which answers what extension an
     * upload should be STORED under and only covers binary media.
     */
    public static function extensionForMimeType(string $mimeType): ?string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        $exact = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            'application/json' => 'json',
            'application/x-ndjson' => 'ndjson',
            'application/csv' => 'csv',
            'application/x-csv' => 'csv',
        ];

        if (isset($exact[$mimeType])) {
            return $exact[$mimeType];
        }

        // A structured-syntax suffix (RFC 6839) is the parser of its base type: application/ld+json
        // is JSON, application/atom+xml is XML.
        if (str_ends_with($mimeType, '+json')) {
            return 'json';
        }

        if (str_ends_with($mimeType, '+xml')) {
            return 'xml';
        }

        // Everything else textual needs no parser, so any listed text extension routes it.
        return str_starts_with($mimeType, 'text/') || in_array($mimeType, self::TEXTUAL_MIME_TYPES, true)
            ? 'txt'
            : null;
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

        return $this->extractFrom($this->readBytes($file), $extension);
    }

    /**
     * Files already managed by Kanvas should be read through the app's configured storage client.
     * Besides avoiding a second public HTTP hop, this keeps local/private S3 endpoints compatible
     * with the SSRF guard, which correctly rejects RFC1918 and container-only hostnames.
     */
    private function readBytes(Filesystem $file): string
    {
        try {
            $storage = new FilesystemServices($file->app, $file->company);
            $bytes = $storage->getStorageByDisk()->get($file->path);

            if ($bytes !== '') {
                return $bytes;
            }
        } catch (Throwable) {
            // Legacy/external Filesystem rows may not belong to the configured bucket. Their public
            // URL remains supported, with the same SSRF validation and response-size cap as before.
        }

        return SafeUrlFetcher::fetch($file->url);
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

            // One over the ceiling, so a member that reached it is known to have more behind it.
            $xml = $zip->getFromName('word/document.xml', $this->maxArchiveMemberBytes + 1);
            $zip->close();

            if (! is_string($xml) || $xml === '') {
                return '';
            }

            $truncated = strlen($xml) > $this->maxArchiveMemberBytes;

            if ($truncated) {
                $xml = substr($xml, 0, $this->maxArchiveMemberBytes);
            }

            $spaced = preg_replace(
                ['#</w:p>#', '#<w:tab[^>]*/>#'],
                ["\n", "\t"],
                $xml,
            );

            $text = $this->normalize(html_entity_decode(strip_tags((string) $spaced), ENT_QUOTES | ENT_XML1));

            return $truncated ? $text . "\n… [truncated]" : $text;
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
            $reader = IOFactory::createReader(match ($extension) {
                'xls' => 'Xls',
                'ods' => 'Ods',
                default => 'Xlsx',
            });

            // renderSpreadsheet() caps the OUTPUT, which is only reached once load() has already
            // built every row (and, since read-data-only is off, every style) in memory. Bounding
            // the read itself is what keeps a large workbook from costing that before the cap runs.
            $reader->setReadFilter(new class (self::MAX_SPREADSHEET_ROWS) implements IReadFilter {
                public function __construct(
                    private readonly int $maxRows,
                ) {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->maxRows;
                }
            });

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
