<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Channels\Models\Channel;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Every comment an agent leaves on a plan goes to its Activities channel. A plan whose channel failed to
 * be created took every comment as `posted: false`, and the agent working it had nowhere to say it was stuck.
 */
final class PlanActivityChannelTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function testAPlanWithALongDescriptionStillGetsItsActivitiesChannel(): void
    {
        Bus::fake();

        $plan = $this->makePlan(['description' => str_repeat('Update the approved technical plan and deliver it. ', 20)]);

        $channel = Channel::query()
            ->where('entity_namespace', Plan::class)
            ->where('entity_id', (string) $plan->getId())
            ->first();

        $this->assertNotNull($channel, 'the plan has no Activities channel, so no comment can be posted on it');
        $this->assertLessThanOrEqual(255, mb_strlen((string) $channel->description));
    }
}
