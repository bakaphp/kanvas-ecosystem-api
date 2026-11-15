<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Actions\PruneIntegrationHistoryAction;
use Kanvas\Workflow\Rules\Actions\EnsureAppDefaultRulesAction;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Repositories\RuleRepository;
use Tests\TestCase;
use Tests\Workflow\Support\CataloguesStubActivities;
use Tests\Workflow\Support\PlainRecordingActivity;

/**
 * What a company does on its own is one rule per app for every company; a company's own rule for
 * the same activity replaces it; and the history those runs leave is kept to its retention.
 */
final class AppDefaultRulesTest extends TestCase
{
    use CataloguesStubActivities;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm'];

    private const string RULE_NAME = 'Test app default: plain recording';

    protected function setUp(): void
    {
        parent::setUp();

        config(['workflows.app_default_rules' => [[
            'trigger' => WorkflowEnum::PULL->value,
            'model' => Lead::class,
            'activity' => PlainRecordingActivity::class,
            'name' => self::RULE_NAME,
        ]]]);
    }

    public function testEveryCompanyOfTheAppGetsOneAsyncRuleThatOnlyTriggers(): void
    {
        $app = app(Apps::class);

        [$rule] = new EnsureAppDefaultRulesAction($app)->execute();
        new EnsureAppDefaultRulesAction($app)->execute();

        $this->assertSame(0, (int) $rule->companies_id);
        $this->assertSame($app->getId(), (int) $rule->apps_id);
        $this->assertTrue($rule->is_async);
        $this->assertTrue($rule->isAppDefault());
        $this->assertSame(1, $this->rulesNamed($app), 'installing twice is still one rule');
    }

    public function testARuleSomeoneDeletedComesBack(): void
    {
        $app = app(Apps::class);
        [$rule] = new EnsureAppDefaultRulesAction($app)->execute();
        $rule->forceFill(['is_deleted' => 1])->saveOrFail();

        new EnsureAppDefaultRulesAction($app)->execute();

        $this->assertSame(0, (int) $rule->fresh()->is_deleted);
    }

    public function testACompanysOwnRuleForTheSameActivityReplacesTheAppDefault(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        [$appDefault] = new EnsureAppDefaultRulesAction($app)->execute();

        $this->assertTrue($this->rulesFor($app, $company)->contains(fn (Rule $rule): bool => $rule->is($appDefault)));

        $own = new WireRuleAction(
            app: $app,
            companyId: $company->getId(),
            entityClass: Lead::class,
            ruleTypeName: WorkflowEnum::PULL->value,
            activityClass: PlainRecordingActivity::class,
            name: 'Test company rule: plain recording',
            description: 'the company wrote its own',
        )->execute();

        $rules = $this->rulesFor($app, $company);

        $this->assertTrue($rules->contains(fn (Rule $rule): bool => $rule->is($own)));
        $this->assertFalse($rules->contains(fn (Rule $rule): bool => $rule->is($appDefault)), 'the activity would run twice');
    }

    public function testACompanyWithNoRuleOfItsOwnGetsTheAppDefaultWithoutLoadingAnyStep(): void
    {
        $app = app(Apps::class);
        [$appDefault] = new EnsureAppDefaultRulesAction($app)->execute();
        $company = Companies::factory()->create();
        $workflow = DB::connection('workflow');

        $workflow->enableQueryLog();
        $rules = $this->rulesFor($app, $company);
        $queries = implode("\n", array_column($workflow->getQueryLog(), 'query'));
        $workflow->disableQueryLog();

        $this->assertTrue($rules->contains(fn (Rule $rule): bool => $rule->is($appDefault)));
        $this->assertStringNotContainsString(new RuleAction()->getTable(), $queries);
    }

    public function testTheCommandInstallsTheRulesOfOneAppAndRefusesAnAppItCannotFind(): void
    {
        $app = app(Apps::class);

        $this->artisan('kanvas:workflow-ensure-app-default-rules', ['app_id' => $app->getId()])
            ->assertSuccessful();
        $this->assertSame(1, $this->rulesNamed($app));

        $this->expectException(ModelNotFoundException::class);
        $this->artisan('kanvas:workflow-ensure-app-default-rules', ['app_id' => PHP_INT_MAX]);
    }

    public function testHistoryOlderThanTheCutoffGoesAndTheRestStays(): void
    {
        [$deleted, $kept] = $this->pruneBefore('2026-02-01', ['2026-01-01', '2026-01-02', '2026-03-01', '2026-03-02']);

        $this->assertSame(2, $deleted);
        $this->assertSame(['2026-03-01', '2026-03-02'], $kept);
    }

    public function testARowWrittenAfterTheCutoffIsKeptEvenWhenOlderRowsCameAfterIt(): void
    {
        [$deleted, $kept] = $this->pruneBefore(
            '2026-02-01',
            ['2026-01-01', '2026-03-05', '2026-01-02', '2026-01-03', '2026-03-01']
        );

        $this->assertSame(3, $deleted);
        $this->assertSame(['2026-03-05', '2026-03-01'], $kept, 'a row is deleted on its date, never on its position');
    }

    /**
     * On a copy of the table: the real one is shared by every test and swept by age.
     *
     * @param list<string> $days one row per day, in id order
     *
     * @return array{0: int, 1: list<string>} rows deleted, and the days left in id order
     */
    private function pruneBefore(string $cutoff, array $days): array
    {
        $workflow = DB::connection('workflow');
        $workflow->statement('CREATE TEMPORARY TABLE eih_prune_test LIKE ' . PruneIntegrationHistoryAction::TABLE);
        $statusId = (int) $workflow->table('status')->where('apps_id', 0)->value('id');

        try {
            foreach ($days as $day) {
                $workflow->table('eih_prune_test')->insert([
                    'entity_namespace' => Lead::class,
                    'entity_id' => '1',
                    'apps_id' => 1,
                    'status_id' => $statusId,
                    'created_at' => $day,
                    'updated_at' => $day,
                ]);
            }

            $deleted = new PruneIntegrationHistoryAction(
                cutoff: Carbon::parse($cutoff),
                deadline: now()->addMinute(),
                batch: 1,
                table: 'eih_prune_test',
            )->execute();

            $kept = $workflow->table('eih_prune_test')
                ->orderBy('id')
                ->pluck('created_at')
                ->map(fn ($at): string => substr((string) $at, 0, 10))
                ->all();

            return [$deleted, $kept];
        } finally {
            $workflow->statement('DROP TEMPORARY TABLE IF EXISTS eih_prune_test');
        }
    }

    private function rulesFor(Apps $app, CompanyInterface $company): Collection
    {
        return RuleRepository::getRulesByModelAndType(
            $app,
            Lead::factory()->make(['companies_id' => $company->getId(), 'apps_id' => $app->getId()]),
            RuleType::getByName(WorkflowEnum::PULL->value),
            $company
        );
    }

    private function rulesNamed(Apps $app): int
    {
        return Rule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', 0)
            ->where('name', self::RULE_NAME)
            ->where('is_deleted', 0)
            ->count();
    }
}
