<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class AgentSlugTest extends TestCase
{
    private function makeAgent(array $overrides = []): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_merge([
                'user_id' => $user->getId(),
                'is_active' => true,
            ], $overrides));
    }

    public function testSlugEndsWithAgentId(): void
    {
        $agent = $this->makeAgent(['name' => 'Support Agent']);
        $agent->refresh();

        $this->assertSame('support-agent-' . $agent->id, $agent->slug);
    }

    public function testSameNameAgentsGetDistinctSlugs(): void
    {
        $first = $this->makeAgent(['name' => 'Collision Agent']);
        $second = $this->makeAgent(['name' => 'Collision Agent']);

        $first->refresh();
        $second->refresh();

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame('collision-agent-' . $first->id, $first->slug);
        $this->assertSame('collision-agent-' . $second->id, $second->slug);
    }

    public function testExplicitSlugStillGetsIdAppended(): void
    {
        $agent = $this->makeAgent([
            'name' => 'Named Agent',
            'slug' => 'custom-slug',
        ]);
        $agent->refresh();

        $this->assertSame('custom-slug-' . $agent->id, $agent->slug);
    }
}
