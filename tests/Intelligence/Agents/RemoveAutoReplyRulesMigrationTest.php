<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Triggers\Workflows\TriggerIntelligenceActivity;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The migration walks the shared action catalog, so it never runs beside another test.
 */
#[Group('serial')]
final class RemoveAutoReplyRulesMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const string RESPONDER = 'Kanvas\\Communication\\Workflows\\AgentChannelResponderActivity';

    protected $connectionsToTransact = [null, 'workflow'];

    public function testARuleLeftWithNoStepIsRemovedAndOneWithAnotherStepKeepsIt(): void
    {
        $seeded = $this->rule('Communication auto-reply', [self::RESPONDER]);
        $handBuilt = $this->rule('Answer my texts', [self::RESPONDER]);
        $withMore = $this->rule('Answer and set the mode', [self::RESPONDER, TriggerIntelligenceActivity::class]);

        (require database_path('migrations/Workflow/2026_11_13_110000_remove_communication_auto_reply_rules.php'))->up();

        $this->assertSame(1, (int) $seeded->fresh()->is_deleted);
        $this->assertSame(1, (int) $handBuilt->fresh()->is_deleted, 'a rule with no step left runs nothing');
        $this->assertSame(0, (int) $withMore->fresh()->is_deleted, 'the rule keeps its other step');
        $this->assertSame(1, $withMore->workflowActivities()->where('is_deleted', 0)->count());
        $this->assertSame(
            0,
            WorkflowAction::query()->where('model_name', self::RESPONDER)->where('is_deleted', 0)->count()
        );
    }

    /**
     * @param list<string> $activities
     */
    private function rule(string $name, array $activities): Rule
    {
        $rule = Rule::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'rules_types_id' => RuleType::firstOrCreate(
                ['name' => WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value],
                ['is_deleted' => 0]
            )->getId(),
            'name' => $name,
        ]);

        foreach ($activities as $weight => $activity) {
            $action = WorkflowAction::factory()->create([
                'name' => class_basename($activity) . ' ' . uniqid(),
                'model_name' => $activity,
                'kind' => ActionKindEnum::WORKFLOW->value,
            ]);
            RuleAction::factory()->create([
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => RuleWorkflowAction::factory()->create(['actions_id' => $action->getId()])->getId(),
                'weight' => $weight,
            ]);
        }

        return $rule;
    }
}
