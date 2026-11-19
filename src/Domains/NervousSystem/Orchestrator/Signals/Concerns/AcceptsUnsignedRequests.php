<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Concerns;

use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Request;

/**
 * For a source that does not sign its requests: the receiver URL is the only gate.
 */
trait AcceptsUnsignedRequests
{
    public function authenticate(Request $request, CompanyInterface $company): bool
    {
        return true;
    }

    public function isSigned(CompanyInterface $company): bool
    {
        return false;
    }
}
