<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports\Fakes;

use Kanvas\Imports\RemoteFiles\RemoteFileClient;
use Override;
use RuntimeException;

class FakeRemoteFileClient implements RemoteFileClient
{
    public bool $disconnected = false;

    /**
     * @param array<string, string> $files remote name => contents
     */
    public function __construct(
        private readonly array $files,
        private readonly bool $failOnList = false,
    ) {
    }

    #[Override]
    public function listFiles(): array
    {
        if ($this->failOnList) {
            throw new RuntimeException('Connection reset by peer');
        }

        return array_keys($this->files);
    }

    #[Override]
    public function downloadTo(string $remotePath, string $localPath): bool
    {
        if (! array_key_exists($remotePath, $this->files)) {
            return false;
        }

        file_put_contents($localPath, $this->files[$remotePath]);

        return true;
    }

    #[Override]
    public function disconnect(): void
    {
        $this->disconnected = true;
    }
}
