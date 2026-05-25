<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Providers\OpenClawProvider;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use LogicException;
use Tests\TestCase;

/**
 * Locks the contract default: providers that haven't implemented
 * collectSessionTranscripts() throw a clear LogicException from
 * AbstractAgentRuntimeProvider rather than silently no-op'ing or
 * leaking a runtime-specific error.
 *
 * OpenClaw is the canary today — when/if it grows transcript support
 * (the OpenClaw container also produces session JSONs), this test
 * needs to be deleted along with the assertion that "it doesn't
 * support session transcript collection".
 */
class OpenClawProviderUnsupportedTranscriptCollectionTest extends TestCase
{
    public function testOpenClawProviderThrowsUnsupportedOnCollectSessionTranscripts(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $provider = new OpenClawProvider();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/does not support session transcript collection/i');

        // The deployment is irrelevant — the abstract default-throws before
        // touching it. Passing a fresh unsaved row to keep the test cheap.
        $provider->collectSessionTranscripts(new AgentDeployment(), $app, $company, null);
    }
}
