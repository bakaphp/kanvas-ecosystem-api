<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * `AnnouncesPlanOutcome::deliverToTheConversation()` returns before touching the transcript, the
 * broadcast or the Slack push when the plan has no creating agent. A run plan without one therefore
 * reported its outcome to nobody but the direct notification — every coding job started from chat.
 */
final class AgentRunPlanOriginTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function testARunPlanRecordsTheAgentThatCreatedIt(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);

        $task = new CreateAgentRunPlanAction(
            agent: $agent,
            brief: 'Add a health endpoint',
            planType: 'coding_job',
            requestedBy: $user,
        )->execute();

        $plan = $task->plan;

        $this->assertSame($agent->getId(), $plan->created_by_agent_id);
        $this->assertSame($agent->getId(), $plan->createdByAgent?->getId());
        $this->assertSame($agent->getId(), $plan->agent_id);
        $this->assertSame($agent->getId(), $task->agent_id);
    }
}
