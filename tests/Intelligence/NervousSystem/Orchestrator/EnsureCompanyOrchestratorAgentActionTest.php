<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Orchestrator\ProjectOrchestratorAgent;
use Kanvas\NervousSystem\Orchestrator\Actions\EnsureCompanyOrchestratorAgentAction;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class EnsureCompanyOrchestratorAgentActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany()];
    }

    private function syncOrchestratorType(Apps $app): AgentType
    {
        return AgentType::factory()->create([
            'apps_id' => 0,
            'name' => 'Project Orchestrator',
            'handler' => ProjectOrchestratorAgent::class,
        ]);
    }

    public function testProvisionsAgentInboxAndReceiver(): void
    {
        [$app, $company] = $this->context();
        $this->syncOrchestratorType($app);

        $agent = new EnsureCompanyOrchestratorAgentAction($app, $company)->execute();

        $this->assertSame('Project Orchestrator', $agent->name);
        $this->assertNotNull($agent->user);

        // Inbox project owned by the orchestrator agent.
        $inbox = Project::query()->where('agent_id', $agent->getId())->first();
        $this->assertNotNull($inbox);
        $this->assertSame('Signal Inbox', $inbox->title);

        // Its receiver is the orchestrator routing job with the source configured.
        $receiver = $inbox->refresh()->receiverWebhook;
        $this->assertNotNull($receiver);
        $this->assertSame(ProcessOrchestratorSignalJob::class, $receiver->action->model_name);
        $this->assertSame('read_ai', $receiver->configuration['signal_source']);
        $this->assertSame($agent->getId(), (int) $receiver->configuration['orchestrator_agent_id']);
        $this->assertSame($inbox->getId(), (int) $receiver->configuration['inbox_project_id']);
    }

    public function testIsIdempotent(): void
    {
        [$app, $company] = $this->context();
        $this->syncOrchestratorType($app);

        $first = new EnsureCompanyOrchestratorAgentAction($app, $company)->execute();
        $second = new EnsureCompanyOrchestratorAgentAction($app, $company)->execute();

        $this->assertSame($first->getId(), $second->getId());

        // Exactly one agent, one inbox, one user, one orchestrator receiver.
        $this->assertSame(
            1,
            Project::query()->where('agent_id', $first->getId())->count(),
            'should not create a second inbox project',
        );
        $this->assertSame(
            1,
            ReceiverWebhook::query()->whereJsonContains('configuration->inbox_project_id', (int) Project::query()->where('agent_id', $first->getId())->value('id'))->count(),
        );
        $this->assertSame($first->user_id, $second->user_id);
    }

    /**
     * Serial: soft-deleting the global `apps_id = 0` catalog row takes an X lock on it for the rest of
     * this transaction, while every parallel process inserting an agent takes the FK parent lock on that
     * same row — the two lock it through different indexes and deadlock (1213), which surfaces as an
     * unrelated `DeadlockException` in whichever test loses. Nothing that mutates a shared global
     * catalog can run inside the parallel lane.
     */
    #[Group('serial')]
    public function testFailsClearlyWhenTypeNotSynced(): void
    {
        [$app, $company] = $this->context();

        // Guarantee the un-synced state within this transaction (a prior run may have leaked a row).
        AgentType::withoutGlobalScopes()
            ->where('handler', ProjectOrchestratorAgent::class)
            ->where('apps_id', 0)
            ->delete();

        $this->expectExceptionMessageMatches('/not synced/');
        new EnsureCompanyOrchestratorAgentAction($app, $company)->execute();
    }
}
