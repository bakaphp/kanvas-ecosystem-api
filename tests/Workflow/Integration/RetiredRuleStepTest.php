<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Tests\TestCase;
use Workflow\WorkflowStub;

/**
 * A step is retired by soft-deleting its row, and the rule stays when it has other steps. An
 * activity removed from the code is still named by the rule of a company not yet moved off it.
 * Neither may run: the class is gone, so building it is fatal to the whole run.
 */
final class RetiredRuleStepTest extends TestCase
{
    use DatabaseTransactions;

    private const string REMOVED_ACTIVITY = 'Kanvas\\Connectors\\Mailgun\\Workflows\\AgentChannelResponderActivity';

    protected $connectionsToTransact = ['workflow'];

    public function testARuleDoesNotListASoftDeletedStep(): void
    {
        $rule = $this->ruleWithOneLiveStep();
        $this->addStepRunning(self::REMOVED_ACTIVITY, $rule, isDeleted: 1);

        $this->assertCount(1, $rule->workflowActivities);
        $this->assertSame(1, $rule->workflowActivities()->count());
    }

    public function testASoftDeletedStepNamingARemovedActivityDoesNotRun(): void
    {
        $rule = $this->ruleWithOneLiveStep();
        $this->addStepRunning(self::REMOVED_ACTIVITY, $rule, isDeleted: 1);

        $run = $this->fire();

        $this->assertTrue($run->completed(), 'The run failed: ' . json_encode($run->exceptions()));
        $this->assertCount(1, $run->output());
    }

    public function testALiveStepNamingARemovedActivityIsLeftOutOfTheRun(): void
    {
        $rule = $this->ruleWithOneLiveStep();
        $this->addStepRunning(self::REMOVED_ACTIVITY, $rule, isDeleted: 0);

        $run = $this->fire();

        $this->assertTrue($run->completed(), 'The run failed: ' . json_encode($run->exceptions()));
        $this->assertCount(1, $run->output());
    }

    public function testAStepNamingAClassThatIsNotAnActivityIsLeftOutOfTheRun(): void
    {
        $rule = $this->ruleWithOneLiveStep();
        $this->addStepRunning(Lead::class, $rule, isDeleted: 0);

        $run = $this->fire();

        $this->assertTrue($run->completed(), 'The run failed: ' . json_encode($run->exceptions()));
        $this->assertCount(1, $run->output());
    }

    /**
     * On `pull`, which nothing else listens to for leads, so the run below is this rule's alone.
     */
    private function ruleWithOneLiveStep(): Rule
    {
        $step = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $step->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $rule = Rule::query()->findOrFail($step->rules_id);
        $rule->forceFill(['rules_types_id' => RuleType::getByName(WorkflowEnum::PULL->value)->getId()])->saveOrFail();

        return $rule;
    }

    private function addStepRunning(string $class, Rule $rule, int $isDeleted): void
    {
        $action = Action::query()->firstOrCreate(['model_name' => $class], ['name' => class_basename($class)]);
        $workflowAction = RuleWorkflowAction::query()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $rule->systems_modules_id,
        ]);

        RuleAction::query()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $workflowAction->getId(),
            'weight' => 1,
            'is_deleted' => $isDeleted,
        ]);
    }

    private function fire(): WorkflowStub
    {
        $lead = Lead::first() ?? Lead::factory()->create();

        return new ProcessWorkflowEventAction(app(Apps::class), $lead)->execute(WorkflowEnum::PULL->value);
    }
}
