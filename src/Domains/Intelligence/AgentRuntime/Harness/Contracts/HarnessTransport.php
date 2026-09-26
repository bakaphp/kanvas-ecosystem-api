<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Contracts;

/**
 * How a request reaches the runtime's HTTP API. Two implementations exist because the answer depends
 * on where the container runs, not on what we are asking it: plain HTTP when the API can route to the
 * container (same Docker network, or a private address), and `docker exec curl` over SSH when it
 * cannot. Everything above this interface is identical either way.
 */
interface HarnessTransport
{
    /**
     * @param array<string, mixed>|null $body encoded as JSON when present
     * @return array<string, mixed>|list<mixed> decoded response
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        int $timeout = 30
    ): array;
}
