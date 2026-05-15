<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentSwarmAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Enums\AgentSwarmStatusEnum;
use Tests\TestCase;

class AgentSwarmChannelTest extends TestCase
{
    public function testCreatingASwarmCreatesAnActivitiesChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $swarm = new CreateAgentSwarmAction(
            new AgentSwarmData(
                name: 'Marketing org · ' . uniqid(),
                description: 'Org-level swarm for activities feed test',
                status: AgentSwarmStatusEnum::ACTIVE,
                config: null,
                app: $app,
                company: $company,
                user: $user,
            ),
        )->execute();

        $channels = $swarm->socialChannels;

        $this->assertGreaterThanOrEqual(1, $channels->count());
        $first = $channels->first();
        $this->assertNotNull($first);
        $this->assertSame('Activities', $first->name);
        $this->assertSame((string) $swarm->id, (string) $first->entity_id);
        $this->assertSame($swarm->uuid, $first->slug);
    }

    public function testDeletingASwarmCascadesToItsChannels(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $swarm = new CreateAgentSwarmAction(
            new AgentSwarmData(
                name: 'Doomed swarm · ' . uniqid(),
                description: 'Will be deleted',
                status: AgentSwarmStatusEnum::ACTIVE,
                config: null,
                app: $app,
                company: $company,
                user: $user,
            ),
        )->execute();

        $channel = $swarm->socialChannels->first();
        $this->assertNotNull($channel);
        $channelId = $channel->id;

        $swarm->delete();

        $stillActive = $swarm->socialChannels()
            ->where('id', $channelId)
            ->where('is_deleted', 0)
            ->count();

        $this->assertSame(0, $stillActive);
    }
}
