<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Tests\TestCase;

final class CopyCompanyRulesCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

    public function testCopiesAllRulesWithConditionsAndActivities(): void
    {
        $app = app(Apps::class);
        $sourceCompanyId = 987651;
        $targetCompanyId = 987652;

        $rule = Rule::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $sourceCompanyId,
            'name' => 'Copy Me',
            'pattern' => '1 and 2',
            'params' => ['foo' => 'bar'],
        ]);
        RuleCondition::factory()->create(['rules_id' => $rule->getId(), 'attribute_name' => 'status', 'operator' => '==', 'value' => 'new']);
        RuleCondition::factory()->create(['rules_id' => $rule->getId(), 'attribute_name' => 'amount', 'operator' => '>', 'value' => '100']);
        $activity = RuleAction::factory()->create(['rules_id' => $rule->getId(), 'weight' => 1]);

        $this->artisan('workflow:copy-company-rules', [
            'source_company_id' => $sourceCompanyId,
            'target_company_id' => $targetCompanyId,
        ])->assertExitCode(0);

        $copied = Rule::where('companies_id', $targetCompanyId)->where('name', 'Copy Me')->first();
        $this->assertNotNull($copied);
        $this->assertNotEquals($rule->getId(), $copied->getId());
        $this->assertEquals('1 and 2', $copied->pattern);
        $this->assertEquals(['foo' => 'bar'], $copied->params);
        $this->assertEquals($rule->rules_types_id, $copied->rules_types_id);

        $this->assertCount(2, $copied->getRulesConditions);
        $this->assertCount(1, $copied->workflowActivities);
        // rules_workflow_actions rows are shared lookups — the pivot reuses the same id
        $this->assertEquals($activity->rules_workflow_actions_id, $copied->workflowActivities->first()->rules_workflow_actions_id);
    }

    public function testCopiesOnlyRequestedRuleIds(): void
    {
        $app = app(Apps::class);
        $sourceCompanyId = 987653;
        $targetCompanyId = 987654;

        $wanted = Rule::factory()->create(['apps_id' => $app->getId(), 'companies_id' => $sourceCompanyId, 'name' => 'Wanted']);
        Rule::factory()->create(['apps_id' => $app->getId(), 'companies_id' => $sourceCompanyId, 'name' => 'Skipped']);

        $this->artisan('workflow:copy-company-rules', [
            'source_company_id' => $sourceCompanyId,
            'target_company_id' => $targetCompanyId,
            '--rule-ids' => (string) $wanted->getId(),
        ])->assertExitCode(0);

        $this->assertEquals(1, Rule::where('companies_id', $targetCompanyId)->count());
        $this->assertEquals('Wanted', Rule::where('companies_id', $targetCompanyId)->first()->name);
    }

    public function testFailsWhenSourceHasNoRules(): void
    {
        $this->artisan('workflow:copy-company-rules', [
            'source_company_id' => 987655,
            'target_company_id' => 987656,
        ])->assertExitCode(1);
    }
}
