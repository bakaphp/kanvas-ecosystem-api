<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes\Kanban;

use Kanvas\Connectors\Hermes\Kanban\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\Kanban\Support\HermesProfileResolver;
use Kanvas\Intelligence\Agents\Models\Agent;
use Mockery;
use PHPUnit\Framework\TestCase;

final class HermesProfileResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testForAgentUsesTheProfileCustomFieldWhenSet(): void
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')
            ->with(CustomFieldEnum::HERMES_KANBAN_PROFILE->value)
            ->andReturn('linguist');

        $this->assertSame('linguist', HermesProfileResolver::forAgent($agent));
    }

    public function testForAgentFallsBackToDefaultProfileWhenNoCustomField(): void
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')
            ->with(CustomFieldEnum::HERMES_KANBAN_PROFILE->value)
            ->andReturn(null);

        // Docker-isolation container's single profile is `default`, not the agent slug.
        $this->assertSame('default', HermesProfileResolver::forAgent($agent));
    }
}
