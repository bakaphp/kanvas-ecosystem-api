<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * An agent fixture must never share its user with the acting human.
 *
 * This is invisible on a seeded database — AgentFactory used to hardcode `user_id => 1`, which is
 * nobody locally but IS the test user on a fresh CI database. When they collide, every "is this
 * actor an agent?" check inverts: self-approval fires against a real person, a human comment reads
 * as agent-authored and wakes no one, and a mention notification is suppressed. Seven CI failures,
 * none reproducible locally.
 */
class AgentFixtureIdentityTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function testTheSharedPlanFixtureGivesAnAgentItsOwnUser(): void
    {
        $agent = $this->makeAgent();

        $this->assertNotSame(
            (int) static::$cachedUser->getId(),
            (int) $agent->user_id,
            'The agent shares the acting human\'s user — every agent-vs-human check will invert.',
        );
        $this->assertNotNull($agent->user, 'The agent user must exist, or write tools have no actor.');
    }

    public function testTheFactoryDefaultDoesNotLandOnTheActingHuman(): void
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(static::$cachedUser->getCurrentCompany()->getId())
            ->create();

        $this->assertNotSame((int) static::$cachedUser->getId(), (int) $agent->user_id);
        $this->assertNotSame(1, (int) $agent->user_id, 'A hardcoded id collides with user 1 on a fresh database.');
    }
}
