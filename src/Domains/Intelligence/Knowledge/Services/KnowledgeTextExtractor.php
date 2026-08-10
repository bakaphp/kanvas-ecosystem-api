<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use Smalot\PdfParser\Parser;

/**
 * Turns an uploaded knowledge file into plain text for indexing. v1 supports
 * plain text/markdown and PDF (smalot/pdfparser, pure-PHP — no shell/ImageMagick
 * surface). DOCX would need phpoffice/phpword, which is not installed. Anything
 * unsupported or unreadable returns '' so the caller treats it as a benign skip
 * rather than a fault.
 */
final class KnowledgeTextExtractor
{
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown'];

    private const PDF_EXTENSIONS = ['pdf'];

    public function supports(Filesystem $file): bool
    {
        return in_array($this->extension($file), [...self::TEXT_EXTENSIONS, ...self::PDF_EXTENSIONS], true);
    }

    public function extract(Filesystem $file): string
    {
        $bytes = SafeUrlFetcher::fetch($file->url);
        $extension = $this->extension($file);

        return match (true) {
            in_array($extension, self::TEXT_EXTENSIONS, true) => $this->normalize($bytes),
            in_array($extension, self::PDF_EXTENSIONS, true) => $this->extractPdf($bytes),
            default => '',
        };
    }

    private function extractPdf(string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF')) {
            return '';
        }

        return $this->normalize(new Parser()->parseContent($bytes)->getText());
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
