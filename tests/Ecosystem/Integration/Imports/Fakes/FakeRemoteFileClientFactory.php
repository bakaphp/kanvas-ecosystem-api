<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports\Fakes;

use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\RemoteFiles\RemoteFileClient;
use Kanvas\Imports\RemoteFiles\RemoteFileClientFactory;
use Override;

class FakeRemoteFileClientFactory extends RemoteFileClientFactory
{
    public function __construct(
        public readonly FakeRemoteFileClient $client,
    ) {
    }

    #[Override]
    public function make(ImportConnection $connection, ?string $root = null): RemoteFileClient
    {
        return $this->client;
    }
}
