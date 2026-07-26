<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Contracts;

use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;

/**
 * Adapts one source's raw webhook payload into the normalized InboundSignal. Each SignalSourceEnum case
 * maps to one implementation via `SignalSourceEnum::adapter()`; the implementation owns that source's
 * field mapping, signature/auth verification, and any (SSRF-guarded) content fetch.
 */
interface SignalSourceAdapter
{
    /**
     * @param array<string, mixed> $payload the raw source webhook body
     */
    public function parse(array $payload): InboundSignal;
}
