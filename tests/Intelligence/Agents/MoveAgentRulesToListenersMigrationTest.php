<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Workflows\TriggerIntelligenceActivity;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The migration walks every rule in the database and the shared action catalog, so it never runs
 * beside another test.
 */
#[Group('serial')]
final class MoveAgentRulesToListenersMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const string LEGACY_AI_ASSIST = 'Kanvas\\Connectors\\SalesAssist\\Activities\\AIAssistChannelResponderActivity';

    protected $connectionsToTransact = [null, 'workflow', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    public function testAPlainReachOutRuleBecomesTheSwitchOnItsAgent(): void
    {
        $agent = $this->agent();
        $rule = $this->rule(
            [TriggerIntelligenceActivity::class, SalesAssistAgentReachOutActivity::class],
            [
                'trigger_type' => TriggersEnum::NEW_LEAD->value,
                'agent_id' => $agent->getId(),
                'lead_source_allowlist' => ['website'],
            ]
        );
        $this->condition($rule, 'id', RuleConditionOperatorEnum::GREATER_THAN, '0');

        $this->migrate();

        $this->assertTrue($agent->fresh()->reachesOutToNewLeads());
        $this->assertSame(['website'], $agent->fresh()->reachOutLeadSources());
        $this->assertSame(1, (int) $rule->fresh()->is_deleted);
    }

    public function testARuleWithoutAnAgentOfItsOwnSwitchesOnTheOneTheCompanySettingNamed(): void
    {
        $agent = $this->agent();
        $rule = $this->rule([SalesAssistAgentReachOutActivity::class], ['trigger_type' => TriggersEnum::NEW_LEAD->value]);
        $setting = ['companies_id' => $this->company->getId(), 'name' => 'agent_reach_out_default_agent_id'];
        $settings = DB::connection('ecosystem')->table('companies_settings');
        $previous = (clone $settings)->where($setting)->value('value');

        // The ecosystem connection is outside the test transaction: put back what was there.
        (clone $settings)->updateOrInsert($setting, ['value' => json_encode($agent->getId())]);

        try {
            $this->migrate();
        } finally {
            $previous === null
                ? (clone $settings)->where($setting)->delete()
                : (clone $settings)->where($setting)->update(['value' => $previous]);
        }

        $this->assertTrue($agent->fresh()->reachesOutToNewLeads());
        $this->assertSame(1, (int) $rule->fresh()->is_deleted);
    }

    public function testANarrowedRuleWithoutAnAgentOfItsOwnIsGivenTheOneTheCompanySettingNamed(): void
    {
        $agent = $this->agent();
        $narrowed = $this->rule([SalesAssistAgentReachOutActivity::class], ['allow_resend' => true]);
        $setting = ['companies_id' => $this->company->getId(), 'name' => 'agent_reach_out_default_agent_id'];
        $settings = DB::connection('ecosystem')->table('companies_settings');
        $previous = (clone $settings)->where($setting)->value('value');

        // The ecosystem connection is outside the test transaction: put back what was there.
        (clone $settings)->updateOrInsert($setting, ['value' => json_encode($agent->getId())]);

        try {
            $this->migrate();
        } finally {
            $previous === null
                ? (clone $settings)->where($setting)->delete()
                : (clone $settings)->where($setting)->update(['value' => $previous]);
        }

        $this->assertSame(0, (int) $narrowed->fresh()->is_deleted);
        $this->assertSame($agent->getId(), $narrowed->fresh()->params['agent_id']);
        $this->assertSame(
            false,
            $agent->fresh()->reach_out_config['new_leads'],
            'setup must find the company already decided, or it would switch an agent on beside the rule'
        );
    }

    public function testAReachOutRuleSomeoneNarrowedKeepsRunningAsARule(): void
    {
        $agent = $this->agent();
        $narrowed = $this->rule([SalesAssistAgentReachOutActivity::class], ['agent_id' => $agent->getId()]);
        $this->condition($narrowed, 'leads_receivers_id', RuleConditionOperatorEnum::EQUAL, '7');
        $resend = $this->rule(
            [SalesAssistAgentReachOutActivity::class],
            ['agent_id' => $agent->getId(), 'allow_resend' => true]
        );

        $this->migrate();

        $this->assertFalse($agent->fresh()->reachesOutToNewLeads());
        $this->assertSame(0, (int) $narrowed->fresh()->is_deleted);
        $this->assertSame(0, (int) $resend->fresh()->is_deleted);
    }

    public function testARuleWithNoAgentBehindItIsLeftForAPersonToLookAt(): void
    {
        $rule = $this->rule([SalesAssistAgentReachOutActivity::class], ['agent_id' => 0]);

        $this->migrate();

        $this->assertSame(0, (int) $rule->fresh()->is_deleted);
    }

    public function testTheSeededAiModeAndAiAssistRulesAreRemoved(): void
    {
        $aiMode = $this->rule([TriggerIntelligenceActivity::class], [], WorkflowEnum::TRIGGER_AI);
        $aiModeOnAnotherEvent = $this->rule([TriggerIntelligenceActivity::class], ['trigger_type' => TriggersEnum::CLOSE_LEAD->value], WorkflowEnum::UPDATED);
        $aiAssist = $this->rule([self::LEGACY_AI_ASSIST], [], WorkflowEnum::UPDATED);
        $aiAssistWithMore = $this->rule([self::LEGACY_AI_ASSIST, TriggerIntelligenceActivity::class], [], WorkflowEnum::UPDATED);

        $this->migrate();

        $this->assertSame(1, (int) $aiMode->fresh()->is_deleted);
        $this->assertSame(0, (int) $aiModeOnAnotherEvent->fresh()->is_deleted, 'a rule a tenant put on another event still runs');
        $this->assertSame(1, (int) $aiAssist->fresh()->is_deleted);
        $this->assertSame(0, (int) $aiAssistWithMore->fresh()->is_deleted, 'the rule keeps its other step');
        $this->assertSame(1, $aiAssistWithMore->workflowActivities()->where('is_deleted', 0)->count());
        $this->assertSame(
            0,
            WorkflowAction::query()->where('model_name', self::LEGACY_AI_ASSIST)->where('is_deleted', 0)->count()
        );
    }

    private function migrate(): void
    {
        (require database_path('migrations/Workflow/2026_11_13_130000_move_agent_rules_to_agent_listeners.php'))->up();
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
    }

    /**
     * @param list<string> $activities
     * @param array<string, mixed> $params
     */
    private function rule(array $activities, array $params, WorkflowEnum $event = WorkflowEnum::CREATED): Rule
    {
        $rule = Rule::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'rules_types_id' => RuleType::firstOrCreate(['name' => $event->value], ['is_deleted' => 0])->getId(),
            'params' => $params,
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

    private function condition(
        Rule $rule,
        string $attribute,
        RuleConditionOperatorEnum $operator,
        string $value
    ): void {
        RuleCondition::query()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => $attribute,
            'operator' => $operator->value,
            'value' => $value,
            'is_custom_attributes' => 0,
        ]);
    }
}
