<?php

declare(strict_types=1);

namespace Tests\Connectors\Kernel;

use Kanvas\Connectors\Kernel\Services\BrowserFiles;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

/**
 * A Kernel browser VM with a known filesystem — the tools' only door into a session, so faking it here
 * exercises everything above it without a browser.
 */
final class FakeBrowserFiles extends BrowserFiles
{
    /**
     * @param array<string, string> $contents absolute path => the file's bytes
     * @param array<string, int> $oversized absolute path => a size the tool must refuse to carry
     */
    public function __construct(
        Agent $agent,
        private readonly array $contents = [],
        private readonly array $oversized = [],
    ) {
        parent::__construct($agent);
    }

    /**
     * @return list<array{path: string, size: int}>
     */
    #[Override]
    public function recent(string $sessionId, array $directories = self::DEFAULT_DIRECTORIES): array
    {
        $files = [];

        // The same filter the real one applies, so a test sees what a sweep would really pick up.
        foreach ($this->contents as $path => $bytes) {
            if (self::isCollectable($path)) {
                $files[] = ['path' => $path, 'size' => strlen($bytes)];
            }
        }

        return $files;
    }

    #[Override]
    public function sizeOf(string $sessionId, string $path): ?int
    {
        return $this->oversized[$path] ?? (isset($this->contents[$path]) ? strlen($this->contents[$path]) : null);
    }

    #[Override]
    public function read(string $sessionId, string $path): ?string
    {
        return isset($this->contents[$path]) ? base64_encode($this->contents[$path]) : null;
    }
}
