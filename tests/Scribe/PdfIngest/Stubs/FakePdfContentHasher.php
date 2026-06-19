<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest\Stubs;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Contracts\PdfContentHasherInterface;

/**
 * Deterministic test hasher — by default returns sha256(uuid) so the same Filesystem row always
 * hashes the same. Test code that wants to simulate two DIFFERENT Filesystem rows with the same
 * content (Path 2 — same PDF, different email message_id) uses queueOverride() to pin two rows
 * to a shared hash.
 */
class FakePdfContentHasher implements PdfContentHasherInterface
{
    /** @var array<string, string> uuid → hash override */
    private array $overrides = [];

    /**
     * Force a specific Filesystem row to hash to a specific value. Use this to simulate two
     * different Filesystem rows sharing the same content bytes.
     */
    public function overrideForUuid(string $uuid, string $hash): self
    {
        $this->overrides[$uuid] = $hash;

        return $this;
    }

    public function hash(Filesystem $pdf): string
    {
        if (isset($this->overrides[$pdf->uuid])) {
            return $this->overrides[$pdf->uuid];
        }

        return hash('sha256', $pdf->uuid);
    }
}
