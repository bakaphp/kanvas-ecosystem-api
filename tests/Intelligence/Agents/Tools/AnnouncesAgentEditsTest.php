<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Tools\Traits\AnnouncesAgentEdits;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\Intelligence\Agents\Tools\Support\RecordingWorkflowActivity;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncPullRules;

/**
 * The announcement is not People-specific: any model with workflows can carry an AGENT_UPDATED rule.
 */
final class AnnouncesAgentEditsTest extends TestCase
{
    use BuildsSyncPullRules;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'workflow', 'ecosystem'];

    public function testAnotherModelsRuleRunsOnAnAgentEdit(): void
    {
        RecordingWorkflowActivity::$entityIds = [];
        $this->createSyncRule(
            app(Apps::class),
            Lead::class,
            WorkflowEnum::AGENT_UPDATED,
            RecordingWorkflowActivity::class,
            'Record agent lead edit ' . uniqid()
        );
        $lead = $this->createLead();

        $this->announcer()->announce($lead);

        $this->assertSame([$lead->getId()], RecordingWorkflowActivity::$entityIds);
    }

    public function testAModelWithoutWorkflowsIsANoOp(): void
    {
        $this->announcer()->announce(new Address());

        $this->addToAssertionCount(1);
    }

    private function announcer(): object
    {
        return new class () {
            use AnnouncesAgentEdits;

            public function announce(Model $entity): void
            {
                $this->announceAgentEdit($entity);
            }
        };
    }
}
