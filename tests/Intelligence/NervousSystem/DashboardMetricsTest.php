<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Actions\BackfillDashboardMetricsAction;
use Kanvas\NervousSystem\Dashboard\Actions\RollupDashboardMetricsAction;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Services\DashboardMetricsAggregatorService;
use Kanvas\NervousSystem\Dashboard\Services\DashboardMetricsService;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    /**
     * Marker plan_type so we can purge only data this test inserted.
     * Real plans never use this string.
     */
    private const string TEST_PLAN_TYPE = 'dashboard-test-marker';

    protected function tearDown(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('plan_type', self::TEST_PLAN_TYPE)
            ->delete();

        DB::connection('intelligence')
            ->table('nervous_system_dashboard_metrics_daily')
            ->whereBetween('metric_date', ['2026-03-01', '2026-03-31'])
            ->delete();

        // Cache between tests would mask state changes — flush for the
        // current tenant so subsequent tests see a fresh aggregate.
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        DashboardMetricsCache::forget($app->getId(), $company->getId());

        parent::tearDown();
    }

    public function testPeriodResolverTodayProducesCurrentDayWindow(): void
    {
        $now = Carbon::parse('2026-04-15 14:30:00', 'UTC');
        $window = new DashboardPeriodResolver()->resolve(DashboardPeriodEnum::TODAY, $now);

        $this->assertSame('2026-04-15 00:00:00', $window['starts_at']->toDateTimeString());
        $this->assertSame('2026-04-15 23:59:59', $window['ends_at']->toDateTimeString());
        $this->assertSame('2026-04-14 00:00:00', $window['prior_starts_at']->toDateTimeString());
        $this->assertSame('2026-04-14 23:59:59', $window['prior_ends_at']->toDateTimeString());
    }

    public function testPeriodResolverThisWeekProducesIsoWeekWindow(): void
    {
        // 2026-04-15 is a Wednesday; ISO week starts Monday 2026-04-13.
        $now = Carbon::parse('2026-04-15 14:30:00', 'UTC');
        $window = new DashboardPeriodResolver()->resolve(DashboardPeriodEnum::THIS_WEEK, $now);

        $this->assertSame('2026-04-13 00:00:00', $window['starts_at']->toDateTimeString());
        $this->assertSame('2026-04-19 23:59:59', $window['ends_at']->toDateTimeString());
        $this->assertSame('2026-04-06 00:00:00', $window['prior_starts_at']->toDateTimeString());
        $this->assertSame('2026-04-12 23:59:59', $window['prior_ends_at']->toDateTimeString());
    }

    public function testAggregatorBucketsCompletedAndMistakeCounts(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-01', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');
        $this->insertPlan($app, $company, $day->copy()->setTime(11, 0), 'done');
        $this->insertPlan($app, $company, $day->copy()->setTime(12, 0), 'failed', errorMessage: 'boom', requiresHumanApproval: false);
        $this->insertPlan($app, $company, $day->copy()->setTime(13, 0), 'done', errorMessage: 'oops', requiresHumanApproval: true);

        $aggregate = new DashboardMetricsAggregatorService()->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );

        $this->assertSame(3, $aggregate->plans_completed);
        $this->assertSame(1, $aggregate->mistakes_auto_corrected);
        $this->assertSame(1, $aggregate->mistakes_escalated);
    }

    public function testAggregatorReturnsNullForUnpopulatedJsonKeys(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-02', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done', output: ['summary' => 'x']);

        $aggregate = new DashboardMetricsAggregatorService()->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );

        $this->assertNull($aggregate->time_recovered_hours);
        $this->assertNull($aggregate->value_delivered_cents);
        $this->assertNull($aggregate->estimated_human_hours);
    }

    public function testAggregatorSumsOutputJsonKeysAcrossPlans(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-03', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done', output: [
            'time_recovered_hours' => 2.5,
            'value_delivered_cents' => 12000,
            'estimated_human_hours' => 8,
        ]);
        $this->insertPlan($app, $company, $day->copy()->setTime(11, 0), 'done', output: [
            'time_recovered_hours' => 1.25,
            'value_delivered_cents' => 4500,
            'estimated_human_hours' => 4,
        ]);

        $aggregate = new DashboardMetricsAggregatorService()->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );

        $this->assertSame(3.75, $aggregate->time_recovered_hours);
        $this->assertSame(16500, $aggregate->value_delivered_cents);
        $this->assertSame(12.0, $aggregate->estimated_human_hours);
    }

    public function testRollupCreatesSnapshotRow(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-04', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done', output: [
            'value_delivered_cents' => 50000,
        ]);

        $snapshot = new RollupDashboardMetricsAction($app, $company, $day)->execute();

        $this->assertSame((int) $app->getId(), (int) $snapshot->apps_id);
        $this->assertSame((int) $company->getId(), (int) $snapshot->companies_id);
        $this->assertSame($day->toDateString(), $snapshot->metric_date->toDateString());
        $this->assertSame(1, (int) $snapshot->plans_completed);
        $this->assertSame(50000, (int) $snapshot->value_delivered_cents);
        $this->assertSame('1.0', $snapshot->formula_version);
    }

    public function testRollupIsIdempotentForSameDate(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-05', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');
        $first = new RollupDashboardMetricsAction($app, $company, $day)->execute();

        $this->insertPlan($app, $company, $day->copy()->setTime(11, 0), 'done');
        $second = new RollupDashboardMetricsAction($app, $company, $day)->execute();

        $this->assertSame($first->id, $second->id, 'Same row reused, no duplicate insert');
        $this->assertSame(2, (int) $second->plans_completed);
    }

    public function testServicePrefersSnapshotForPastDay(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        // Hand-craft a snapshot row claiming numbers different from what
        // live aggregation would return — service must prefer the snapshot.
        DashboardMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => Carbon::parse('2026-03-06', 'UTC')->toDateString(),
            'plans_completed' => 99,
            'mistakes_auto_corrected' => 0,
            'mistakes_escalated' => 0,
            'agents_active' => 5,
            'time_recovered_hours' => null,
            'value_delivered_cents' => null,
            'estimated_human_hours' => null,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);

        $resolver = new DashboardPeriodResolver();
        $window = $resolver->resolve(
            DashboardPeriodEnum::YESTERDAY,
            Carbon::parse('2026-03-07 12:00:00', 'UTC'),
        );

        // Sanity: the YESTERDAY of 2026-03-07 is 2026-03-06 — the snapshot we just made.
        $this->assertSame('2026-03-06', $window['starts_at']->toDateString());

        // Walk the service for the snapshotted day. Result.plans_completed
        // should be 99 (the snapshot), regardless of whether plans exist.
        $aggregate = $this->callServiceForDayUsingFakeNow(
            $app,
            $company,
            DashboardPeriodEnum::YESTERDAY,
            Carbon::parse('2026-03-07 12:00:00', 'UTC'),
        );

        $this->assertSame(99, $aggregate->accomplishments_count);
    }

    public function testServiceFallsBackToLiveWhenSnapshotMissingForPastDay(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-08', 'UTC');

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');
        $this->insertPlan($app, $company, $day->copy()->setTime(11, 0), 'done');
        // No snapshot row — reader must fall back to live aggregation.

        $aggregate = $this->callServiceForDayUsingFakeNow(
            $app,
            $company,
            DashboardPeriodEnum::YESTERDAY,
            $day->copy()->addDay()->setTime(12, 0),
        );

        $this->assertSame(2, $aggregate->accomplishments_count);
    }

    public function testBackfillSkipsExistingSnapshotsByDefault(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-09', 'UTC');

        // Existing snapshot row with bogus count
        DashboardMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => $day->toDateString(),
            'plans_completed' => 999,
            'mistakes_auto_corrected' => 0,
            'mistakes_escalated' => 0,
            'agents_active' => 0,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);

        // Add real plans for that day
        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');

        $result = new BackfillDashboardMetricsAction(
            from: $day,
            to: $day,
            app: $app,
        )->execute();

        $this->assertSame(0, $result['rolled_up'], 'Snapshot already exists, must skip');
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(999, (int) DashboardMetricsDaily::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('metric_date', $day->toDateString())
            ->value('plans_completed'));
    }

    public function testBackfillForceOverridesExistingSnapshot(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-10', 'UTC');

        DashboardMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => $day->toDateString(),
            'plans_completed' => 999,
            'mistakes_auto_corrected' => 0,
            'mistakes_escalated' => 0,
            'agents_active' => 0,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);

        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');

        $result = new BackfillDashboardMetricsAction(
            from: $day,
            to: $day,
            app: $app,
            force: true,
        )->execute();

        $this->assertSame(1, $result['rolled_up']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, (int) DashboardMetricsDaily::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('metric_date', $day->toDateString())
            ->value('plans_completed'));
    }

    public function testGraphQLResolverReturnsExpectedShape(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $today = Carbon::now('UTC');

        $this->insertPlan($app, $company, $today->copy()->setTime(10, 0), 'done', output: [
            'value_delivered_cents' => 25000,
        ]);

        $this->graphQL('
            query {
                nervousSystemDashboardMetrics(period: TODAY) {
                    accomplishments_count
                    accomplishments_count_prior
                    mistakes_caught_count
                    mistakes_auto_corrected
                    mistakes_escalated
                    workforce_leverage
                    human_equivalents
                    time_recovered_hours
                    value_delivered_cents
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'nervousSystemDashboardMetrics' => [
                        'accomplishments_count',
                        'accomplishments_count_prior',
                        'mistakes_caught_count',
                        'mistakes_auto_corrected',
                        'mistakes_escalated',
                        'workforce_leverage',
                        'human_equivalents',
                        'time_recovered_hours',
                        'value_delivered_cents',
                    ],
                ],
            ]);
    }

    public function testBackfillClearsCacheEvenWhenAllDatesSkipped(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $day = Carbon::parse('2026-03-11', 'UTC');

        // Snapshot already exists — backfill will skip without --force.
        DashboardMetricsDaily::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'metric_date' => $day->toDateString(),
            'plans_completed' => 0,
            'mistakes_auto_corrected' => 0,
            'mistakes_escalated' => 0,
            'agents_active' => 0,
            'computed_at' => Carbon::now(),
            'formula_version' => '1.0',
        ]);
        $this->insertPlan($app, $company, $day->copy()->setTime(10, 0), 'done');

        // Pre-warm cache with a sentinel so we can verify it gets cleared.
        $cacheKey = DashboardMetricsCache::key(
            $app->getId(),
            $company->getId(),
            DashboardPeriodEnum::TODAY,
        );
        Cache::put($cacheKey, 'sentinel', DashboardMetricsCache::TTL_SECONDS);

        $result = new BackfillDashboardMetricsAction(
            from: $day,
            to: $day,
            app: $app,
        )->execute();

        $this->assertSame(0, $result['rolled_up'], 'All dates skipped (snapshot already exists)');
        $this->assertSame(1, $result['skipped']);
        $this->assertGreaterThanOrEqual(1, $result['tenants_cache_cleared']);
        $this->assertNull(Cache::get($cacheKey), 'Backfill should still flush cache for skipped tenants');
    }

    public function testServiceCachesResultUntilCacheBustOrTtl(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();
        $today = Carbon::now('UTC');

        $this->insertPlan($app, $company, $today->copy()->setTime(10, 0), 'done');

        $first = new DashboardMetricsService()->compute(
            $app,
            $company,
            DashboardPeriodEnum::TODAY,
        );
        $this->assertSame(1, $first->accomplishments_count);

        // Insert another plan AFTER the first call cached the response.
        // Without busting the cache, the second compute() returns the
        // cached value (1) — proves the cache is actually serving reads.
        $this->insertPlan($app, $company, $today->copy()->setTime(11, 0), 'done');
        $cached = new DashboardMetricsService()->compute(
            $app,
            $company,
            DashboardPeriodEnum::TODAY,
        );
        $this->assertSame(1, $cached->accomplishments_count, 'Cached read should not see the new plan');

        // Bust the cache → next compute() should observe both plans.
        DashboardMetricsCache::forget($app->getId(), $company->getId());
        $fresh = new DashboardMetricsService()->compute(
            $app,
            $company,
            DashboardPeriodEnum::TODAY,
        );
        $this->assertSame(2, $fresh->accomplishments_count);
    }

    public function testRollupBustsTenantCache(): void
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        // Pre-warm cache for all 3 periods.
        Cache::put(
            DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::TODAY),
            'sentinel-today',
            DashboardMetricsCache::TTL_SECONDS,
        );
        Cache::put(
            DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::YESTERDAY),
            'sentinel-yesterday',
            DashboardMetricsCache::TTL_SECONDS,
        );
        Cache::put(
            DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::THIS_WEEK),
            'sentinel-week',
            DashboardMetricsCache::TTL_SECONDS,
        );

        new RollupDashboardMetricsAction(
            $app,
            $company,
            Carbon::parse('2026-03-15', 'UTC'),
        )->execute();

        $this->assertNull(Cache::get(DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::TODAY)));
        $this->assertNull(Cache::get(DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::YESTERDAY)));
        $this->assertNull(Cache::get(DashboardMetricsCache::key($app->getId(), $company->getId(), DashboardPeriodEnum::THIS_WEEK)));
    }

    private function callServiceForDayUsingFakeNow(
        Apps $app,
        Companies $company,
        DashboardPeriodEnum $period,
        Carbon $now,
    ): \Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardMetrics {
        Carbon::setTestNow($now);

        try {
            return new DashboardMetricsService()->compute($app, $company, $period);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function insertPlan(
        Apps $app,
        Companies $company,
        Carbon $completedAt,
        string $status,
        ?string $errorMessage = null,
        bool $requiresHumanApproval = false,
        array $output = [],
    ): int {
        return DB::connection('intelligence')->table('nervous_system_plans')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => null,
            'users_id' => null,
            'plan_type' => self::TEST_PLAN_TYPE,
            'title' => 'Dashboard test ' . uniqid(),
            'status' => $status,
            'priority' => 2,
            'completion_pct' => $status === 'done' ? 100 : 0,
            'completed_at' => $completedAt,
            'requires_human_approval' => $requiresHumanApproval ? 1 : 0,
            'error_message' => $errorMessage,
            'output' => json_encode($output),
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
