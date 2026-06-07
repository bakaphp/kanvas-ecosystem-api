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

    public function testForAgentFallsBackToSlugWhenNoProfileCustomField(): void
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')
            ->with(CustomFieldEnum::HERMES_KANBAN_PROFILE->value)
            ->andReturn(null);
        $agent->shouldReceive('getAttribute')->with('slug')->andReturn('researcher');

        $this->assertSame('researcher', HermesProfileResolver::forAgent($agent));
    }
}
