<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\SyncWorkflowStub;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;

final class SyncWorkflowStubTest extends TestCase
{
    public function testMakeCreatesStoredWorkflow(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);

        $this->assertInstanceOf(SyncWorkflowStub::class, $stub);
        $this->assertIsInt($stub->id());
        $this->assertTrue($stub->created());
    }

    public function testLoadExistingWorkflow(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);
        $id = $stub->id();

        $loaded = SyncWorkflowStub::load($id);

        $this->assertInstanceOf(SyncWorkflowStub::class, $loaded);
        $this->assertEquals($id, $loaded->id());
    }

    public function testFromStoredWorkflow(): void
    {
        $storedWorkflow = StoredWorkflow::create([
            'class' => DynamicRuleWorkflow::class,
        ]);

        $stub = SyncWorkflowStub::fromStoredWorkflow($storedWorkflow);

        $this->assertInstanceOf(SyncWorkflowStub::class, $stub);
        $this->assertEquals($storedWorkflow->id, $stub->id());
    }

    public function testContextIsSetOnConstruction(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);

        $context = SyncWorkflowStub::getContext();
        $this->assertNotNull($context);
        $this->assertEquals(0, $context->index);
        $this->assertFalse($context->replaying);
    }

    public function testNowReturnsCarbon(): void
    {
        SyncWorkflowStub::make(DynamicRuleWorkflow::class);

        $now = SyncWorkflowStub::now();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $now);
    }

    public function testStatusTransitions(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);

        $this->assertTrue($stub->created());
        $this->assertFalse($stub->completed());
        $this->assertFalse($stub->failed());
        $this->assertTrue($stub->running());
    }

    public function testFreshRefreshesWorkflow(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);
        $id = $stub->id();

        $refreshed = $stub->fresh();
        $this->assertInstanceOf(SyncWorkflowStub::class, $refreshed);
        $this->assertEquals($id, $refreshed->id());
    }

    public function testOutputReturnsNullForNewWorkflow(): void
    {
        $stub = SyncWorkflowStub::make(DynamicRuleWorkflow::class);

        $this->assertNull($stub->output());
    }

    public function testSyncWorkflowExecutesThroughProcessWorkflowEventAction(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::CREATED->value, []);

        $this->assertInstanceOf(SyncWorkflowStub::class, $result);
        $this->assertTrue(
            $result->completed() || $result->running(),
            'Sync workflow should have completed or be running after synchronous dispatch'
        );
    }

    public function testSyncWorkflowProducesOutput(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::CREATED->value, []);

        $this->assertInstanceOf(SyncWorkflowStub::class, $result);

        $output = $result->output();
        if ($result->completed()) {
            $this->assertNotNull($output);
            $this->assertIsArray($output);
        }
    }

    public function testSyncWorkflowWithConditionMismatchReturnsEmptyOutput(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'interaction',
            'operator' => '==',
            'value' => 'nonexistent_value_' . uniqid(),
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::CREATED->value, [
            'interaction' => 'something_else',
        ]);

        $this->assertInstanceOf(SyncWorkflowStub::class, $result);
        $this->assertEmpty($result->output());
    }

    public function testGetDefaultPropertiesCachesResults(): void
    {
        $properties1 = SyncWorkflowStub::getDefaultProperties(DynamicRuleWorkflow::class);
        $properties2 = SyncWorkflowStub::getDefaultProperties(DynamicRuleWorkflow::class);

        $this->assertEquals($properties1, $properties2);
        $this->assertIsArray($properties1);
    }
}
