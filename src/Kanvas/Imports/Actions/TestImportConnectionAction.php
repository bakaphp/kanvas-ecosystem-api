<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\RemoteFiles\RemoteFileClientFactory;
use Throwable;

/**
 * Backs the "Test connection" button. Works on an unsaved connection too, so the form can test
 * before its first save. A failure is an answer here, not an error.
 */
class TestImportConnectionAction
{
    public function __construct(
        private readonly ImportConnection $connection,
        private readonly ?string $root = null,
        private readonly RemoteFileClientFactory $clients = new RemoteFileClientFactory(),
    ) {
    }

    /**
     * @return array{connected: bool, message: string|null, files: list<string>}
     */
    public function execute(): array
    {
        try {
            $client = $this->clients->make($this->connection, $this->root);
            $files = $client->listFiles();
            $client->disconnect();
        } catch (Throwable $e) {
            return ['connected' => false, 'message' => $e->getMessage(), 'files' => []];
        }

        return ['connected' => true, 'message' => count($files) . ' file(s) found', 'files' => $files];
    }
}
