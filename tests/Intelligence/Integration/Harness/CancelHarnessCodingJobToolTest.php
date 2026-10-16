<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\CancelHarnessCodingJobTool;
use Kanvas\Users\Models\Users;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;

class CancelHarnessCodingJobToolTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    /**
     * Finalising a live row reads it as FAILED, so without a terminal status first a deliberate cancel
     * is announced as a failed job and its task blocked instead of skipped.
     */
    public function testACancelledJobEndsCancelledNotFailed(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $session = $this->createTaskSession(attributes: [
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
        ]);

        new CancelHarnessCodingJobTool($agent)($session->task_id, 'going the wrong way');

        $session->refresh();
        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $session->status);
        $this->assertSame('Cancelled: going the wrong way', $session->error_message);
    }
}
