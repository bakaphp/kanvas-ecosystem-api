<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Contracts;

use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Request;
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

    /**
     * Whether the request really came from the source. Runs before the job, on the raw request.
     */
    public function authenticate(Request $request, CompanyInterface $company): bool;

    /**
     * Whether the company's requests from this source are signature-checked, recorded on every
     * routing event so unsigned tenants are easy to find.
     */
    public function isSigned(CompanyInterface $company): bool;
}
