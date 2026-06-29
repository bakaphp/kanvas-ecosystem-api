<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Contracts;

use Kanvas\Filesystem\Models\Filesystem;

/**
 * Computes a content-stable hash of a Filesystem-stored PDF for ingest dedup (PR 9.1 — Path 2).
 *
 * "Content-stable" means: two Filesystem rows with the same underlying bytes return the same hash,
 * even if their URLs/uuids/timestamps differ. The orchestrator uses this to short-circuit re-processing
 * of identical PDFs that arrive via different email message ids.
 *
 * Real impl: RemotePdfContentHasherService (fetches bytes via SafeUrlFetcher, hashes SHA-256).
 * Test impl: FakePdfContentHasher (deterministic hash from filesystem uuid — same uuid → same hash).
 */
interface PdfContentHasherInterface
{
    public function hash(Filesystem $pdf): string;
}
