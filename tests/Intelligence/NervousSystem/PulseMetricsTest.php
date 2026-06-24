<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Pulse\Actions\BackfillPulseMetricsAction;
use Kanvas\NervousSystem\Pulse\Actions\RollupPulseMetricsAction;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Services\PulseMetricsAggregatorService;
use Kanvas\NervousSystem\Pulse\Services\PulseMetricsService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class PulseMetricsTest extends TestCase
{
    /** Plan/event marker so tearDown purges only this test's data. */
    private const string TEST_MARKER = 'pulse-test-marker';

    protected function tearDown(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('source_domain', self::TEST_MARKER)
            ->delete();

        DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('plan_type', self::TEST_MARKER)
            ->delete();

        DB::connection('intelligence')
            ->table('nervous_system_pulse_metrics_daily')
            ->whereBetween('metric_date', ['2026-02-01', '2026-02-28'])
            ->delete();

        parent::tearDown();
    }

    public function testCategoryColumnIsWrittenOnEventCreate(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: self::TEST_MARKER,
                eventType: 'plan.created',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        // Read column directly — bypass accessor fallback.
        $raw = DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('id', $event->id)
            ->value('category');

        $this->assertSame('DECIDE', $raw);
    }

    public function testCategoryColumnIsNullForInternalEvents(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: self::TEST_MARKER,
                eventType: 'dashboard.metrics_rolled_up',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        $raw = DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('id', $event->id)
            ->value('category');

        $this->assertNull($raw);
    }

    public function testAggregatorCountsEventsByCategoryInWindow(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-05', 'UTC');

        // 3 signals, 2 acts, 1 warning, all on the test day
        $this->seedEvent($app, $company, $day->copy()->setTime(10, 0), 'signal.lead_detected', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(10, 30), 'signal.lead_detected', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(11, 0), 'lead.created', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(12, 0), 'plan.task.completed', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(13, 0), 'integration.salesforce.completed', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(14, 0), 'integration.netsuite.failed', EventStatusEnum::ERROR);

        $aggregate = new PulseMetricsAggregatorService()->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );

        $this->assertSame(3, $aggregate->signals_count);
        $this->assertSame(2, $aggregate->actions_executed);
        $this->assertSame(1, $aggregate->warnings_count);
    }

    public function testAggregatorReadsConfidenceFromCompletedPlans(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-06', 'UTC');

        $this->seedPlan($app, $company, $day->copy()->setTime(10, 0), confidenceScore: 0.85);
        $this->seedPlan($app, $company, $day->copy()->setTime(11, 0), confidenceScore: 0.95);

        $aggregate = new PulseMetricsAggregatorService()->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );

        // Avg of 0.85 and 0.95 = 0.90 → 90.0%
        $this->assertNotNull($aggregate->system_confidence_pct);
        $this->assertEqualsWithDelta(90.0, $aggregate->system_confidence_pct, 0.5);
    }

    public function testRollupCreatesSnapshotRow(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-07', 'UTC');

        $this->seedEvent($app, $company, $day->copy()->setTime(9, 0), 'signal.lead_detected', EventStatusEnum::INFO);
        $this->seedPlan($app, $company, $day->copy()->setTime(10, 0), errorMessage: 'auto-corrected', confidenceScore: 0.8);

        $snapshot = new RollupPulseMetricsAction($app, $company, $day)->execute();

        $this->assertSame(1, (int) $snapshot->signals_count);
        $this->assertSame(1, (int) $snapshot->prevented_issues);
        $this->assertEqualsWithDelta(80.0, (float) $snapshot->system_confidence_pct, 0.5);
        $this->assertSame('1.0', $snapshot->formula_version);
    }

    public function testRollupIsIdempotent(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-08', 'UTC');

        $this->seedEvent($app, $company, $day->copy()->setTime(9, 0), 'plan.created', EventStatusEnum::INFO);

        $first = new RollupPulseMetricsAction($app, $company, $day)->execute();
        $this->seedEvent($app, $company, $day->copy()->setTime(10, 0), 'plan.approved', EventStatusEnum::INFO);
        $second = new RollupPulseMetricsAction($app, $company, $day)->execute();

        $this->assertSame($first->id, $second->id, 'Same row reused on re-roll');
        $this->assertSame(2, (int) $second->decide_count);
    }

    public function testServicePrefersSnapshotForPastDay(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        // Hand-craft a past-day snapshot with bogus numbers — service
        // must return them (proves snapshot is the source of truth).
        PulseMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => '2026-02-09',
            'signals_count' => 777,
            'understand_count' => 0,
            'decide_count' => 0,
            'actions_executed' => 0,
            'warnings_count' => 0,
            'prevented_issues' => 0,
            'system_confidence_pct' => null,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00', 'UTC'));

        try {
            $metrics = new PulseMetricsService()->compute(
                $app,
                $company,
                DashboardPeriodEnum::YESTERDAY,
            );
            $this->assertSame(777, $metrics->signals_count);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testServiceFallsBackToLiveWhenSnapshotMissing(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-11', 'UTC');

        // Two SIGNALs that day, NO snapshot row created.
        $this->seedEvent($app, $company, $day->copy()->setTime(9, 0), 'signal.lead_detected', EventStatusEnum::INFO);
        $this->seedEvent($app, $company, $day->copy()->setTime(10, 0), 'lead.created', EventStatusEnum::INFO);

        Carbon::setTestNow($day->copy()->addDay()->setTime(12, 0));

        try {
            $metrics = new PulseMetricsService()->compute(
                $app,
                $company,
                DashboardPeriodEnum::YESTERDAY,
            );
            $this->assertSame(2, $metrics->signals_count);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testBackfillSkipsExistingSnapshotsByDefault(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-02-12', 'UTC');

        PulseMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => $day->toDateString(),
            'signals_count' => 999,
            'understand_count' => 0,
            'decide_count' => 0,
            'actions_executed' => 0,
            'warnings_count' => 0,
            'prevented_issues' => 0,
            'system_confidence_pct' => null,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);
        // Add real activity for the day
        $this->seedEvent($app, $company, $day->copy()->setTime(9, 0), 'signal.lead_detected', EventStatusEnum::INFO);

        $result = new BackfillPulseMetricsAction(
            from: $day,
            to: $day,
            app: $app,
        )->execute();

        $this->assertSame(0, $result['rolled_up']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(999, (int) PulseMetricsDaily::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('metric_date', $day->toDateString())
            ->value('signals_count'));
    }

    public function testGraphQLResolverReturnsExpectedShape(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $today = Carbon::now('UTC');

        $this->seedEvent($app, $company, $today->copy()->setTime(10, 0), 'signal.lead_detected', EventStatusEnum::INFO);

        $this->graphQL('
            query {
                nervousSystemPulseMetrics(period: TODAY) {
                    signals_count
                    signals_count_prior
                    actions_executed
                    actions_executed_prior
                    prevented_issues
                    prevented_issues_prior
                    system_confidence_pct
                    system_confidence_pct_prior
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'nervousSystemPulseMetrics' => [
                        'signals_count',
                        'signals_count_prior',
                        'actions_executed',
                        'actions_executed_prior',
                        'prevented_issues',
                        'prevented_issues_prior',
                        'system_confidence_pct',
                        'system_confidence_pct_prior',
                    ],
                ],
            ]);
    }

    private function seedEvent(
        Apps $app,
        Companies $company,
        Carbon $occurredAt,
        string $eventType,
        EventStatusEnum $status,
    ): Event {
        return new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: self::TEST_MARKER,
                eventType: $eventType,
                status: $status,
                occurredAt: $occurredAt,
            ),
        )->execute();
    }

    private function seedPlan(
        Apps $app,
        Companies $company,
        Carbon $completedAt,
        ?string $errorMessage = null,
        ?float $confidenceScore = null,
    ): int {
        return DB::connection('intelligence')->table('nervous_system_plans')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'plan_type' => self::TEST_MARKER,
            'title' => 'Pulse plan ' . uniqid(),
            'status' => 'done',
            'priority' => 1,
            'completion_pct' => 100,
            'completed_at' => $completedAt,
            'confidence_score' => $confidenceScore,
            'error_message' => $errorMessage,
            'requires_human_approval' => 0,
            'is_deleted' => 0,
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
