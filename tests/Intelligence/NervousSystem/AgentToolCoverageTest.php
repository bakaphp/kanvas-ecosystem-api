<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Tests\TestCase;

final class AgentToolCoverageTest extends TestCase
{
    public function testEveryAgentToolCarriesTheAgentToolAttribute(): void
    {
        $missing = new AgentToolDiscoveryService()->findUntagged();

        $this->assertSame(
            [],
            $missing,
            "The following agent tool classes are missing the #[AgentTool] attribute and "
            . "will not sync into the nervous_system_tools catalog:\n  - "
            . implode("\n  - ", $missing)
        );
    }
}
