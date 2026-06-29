<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Contracts\PdfContentHasherInterface;
use Override;
use Throwable;

/**
 * Production hasher: fetches the PDF bytes via the SSRF-guarded fetcher and returns SHA-256.
 *
 * When the fetch fails (URL unreachable, byte cap, SSRF block), falls back to hashing the
 * Filesystem row's uuid + size — a uuid is unique per row so collisions land only when the same
 * row is processed twice, which is the redundant-but-acceptable behavior. We never want the
 * orchestrator to abort on a transient fetch error.
 */
class RemotePdfContentHasherService implements PdfContentHasherInterface
{
    #[Override]
    public function hash(Filesystem $pdf): string
    {
        try {
            $bytes = SafeUrlFetcher::fetch((string) $pdf->url);

            return hash('sha256', $bytes);
        } catch (Throwable) {
            // Conservative fallback — never block the ingest because hashing failed.
            return hash('sha256', $pdf->uuid . '|' . $pdf->size);
        }
    }
}
