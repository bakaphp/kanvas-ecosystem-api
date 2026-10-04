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
            'The following agent tool classes are missing the #[AgentTool] attribute and '
            . "will not sync into the nervous_system_tools catalog:\n  - "
            . implode("\n  - ", $missing)
        );
    }

    /**
     * The catalog reads a Neuron tool's description by reflection, never by instantiating it, so a
     * description built in a constructor lands as NULL and `capability_lookup` cannot find the tool.
     * Such a tool carries a literal summary on its #[AgentTool] attribute instead.
     */
    public function testEveryCatalogEntryHasADescription(): void
    {
        $undescribed = array_column(
            array_filter(
                new AgentToolDiscoveryService()->discover(),
                static fn (array $entry): bool => trim((string) ($entry['description'] ?? '')) === '',
            ),
            'class',
        );

        $this->assertSame(
            [],
            $undescribed,
            'These tools would sync into the catalog with no description; give the #[AgentTool] attribute '
            . "a literal description:\n  - " . implode("\n  - ", $undescribed)
        );
    }
}
