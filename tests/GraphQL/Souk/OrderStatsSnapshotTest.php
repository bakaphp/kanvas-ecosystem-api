<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
use Tests\TestCase;

/**
 * ordersInPeriod is a point-in-time snapshot: the state each order was in at the end of every
 * day in the range. Re-querying a past day must keep returning what was true that day, no matter
 * what happened afterwards.
 *
 * The fixture is a 3-day impound-lot run:
 *   D1  two vehicles come in                            -> deposited 2                  = 2
 *   D2  one of them pays, one new comes in              -> deposited 2, paid 1          = 3
 *   D3  the other pays, both paid ones are released,
 *       one new comes in                                -> deposited 2                  = 2
 *
 * Vehicle A's `paid` row is left with a NULL ended_at on purpose — that is the shape the legacy
 * data is in, and it used to make A count as `paid` on every day after it was already released.
 */
class OrderStatsSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    private const D1 = '2026-06-01';
    private const D2 = '2026-06-02';
    private const D3 = '2026-06-03';

    private Apps $currentApp;
    private string $userEmail;
    private array $statusIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->userEmail = 'stats-snapshot-' . Str::random(12) . '@kanvas.dev';

        $this->seedScenario();
    }

    public function testSnapshotIgnoresTransitionRowsThatWereNeverClosed(): void
    {
        $data = $this->runStats(['deposited', 'paid']);

        $this->assertSame(2, $data[self::D1]['count']);
        $this->assertSame(['deposited' => 2], $data[self::D1]['states']);

        $this->assertSame(3, $data[self::D2]['count']);
        $this->assertSame(['deposited' => 2, 'paid' => 1], $data[self::D2]['states']);

        // Vehicle A was released on D3. Its stale `paid` row (ended_at NULL) must not resurrect it.
        $this->assertSame(2, $data[self::D3]['count']);
        $this->assertSame(['deposited' => 2], $data[self::D3]['states']);
        $this->assertArrayNotHasKey('paid', $data[self::D3]['states']);
    }

    public function testAnOrderIsNeverCountedInTwoStatesOnTheSameDay(): void
    {
        $data = $this->runStats(['deposited', 'paid', 'released']);

        // C + D deposited, A + B released. A must not show up as both `paid` and `released`.
        $this->assertSame(4, $data[self::D3]['count']);
        $this->assertSame(['deposited' => 2, 'released' => 2], $data[self::D3]['states']);
    }

    public function testPastDaysAreStableAfterLaterTransitions(): void
    {
        $upToD2 = $this->runStats(['deposited', 'paid'], self::D1, self::D2);
        $fullRange = $this->runStats(['deposited', 'paid']);

        $this->assertSame($upToD2[self::D1], $fullRange[self::D1]);
        $this->assertSame($upToD2[self::D2], $fullRange[self::D2]);
    }

    /**
     * @return array<string, array{count: int, states: array<string, int>}>
     */
    private function runStats(array $currentCountStates, string $start = self::D1, string $end = self::D3): array
    {
        $result = new GetOrderStatsAction(
            $this->currentApp,
            ['deposited'],
            ['released'],
            $currentCountStates,
            userEmail: $this->userEmail,
        )->execute(
            startDate: $start,
            endDate: $end,
            timezone: 'UTC',
        );

        $byDate = [];
        foreach ($result['ordersInPeriod']['data'] as $day) {
            $states = [];
            foreach ($day['states'] as $state) {
                $states[$state['state']] = $state['count'];
            }

            $byDate[$day['date']] = [
                'count' => $day['count'],
                'states' => $states,
            ];
        }

        return $byDate;
    }

    private function seedScenario(): void
    {
        $orderTypeId = DB::connection('commerce')->table('order_types')->insertGetId([
            'apps_id' => $this->currentApp->getId(),
            'name' => 'Stats Snapshot ' . Str::random(8),
            'is_deleted' => 0,
        ]);

        foreach (['deposited', 'paid', 'released'] as $sequence => $slug) {
            $this->statusIds[$slug] = DB::connection('commerce')->table('order_statuses')->insertGetId([
                'order_types_id' => $orderTypeId,
                'apps_id' => $this->currentApp->getId(),
                'slug' => $slug,
                'name' => ucfirst($slug),
                'sequence' => $sequence + 1,
                'is_deleted' => 0,
            ]);
        }

        $vehicles = [];
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $vehicles[$key] = DB::connection('commerce')->table('orders')->insertGetId([
                'apps_id' => $this->currentApp->getId(),
                'companies_id' => 0,
                'region_id' => 0,
                'people_id' => 0,
                'uuid' => (string) Str::uuid(),
                'order_types_id' => $orderTypeId,
                'user_email' => $this->userEmail,
                'is_deleted' => 0,
            ]);
        }

        $this->addTransition($vehicles['a'], 'deposited', self::D1 . ' 08:00:00', self::D2 . ' 10:00:00');
        // Legacy shape: superseded on D3 but never closed.
        $this->addTransition($vehicles['a'], 'paid', self::D2 . ' 10:00:00', null);
        $this->addTransition($vehicles['a'], 'released', self::D3 . ' 11:00:00', null);

        $this->addTransition($vehicles['b'], 'deposited', self::D1 . ' 09:00:00', self::D3 . ' 10:00:00');
        $this->addTransition($vehicles['b'], 'paid', self::D3 . ' 10:00:00', self::D3 . ' 11:00:00');
        $this->addTransition($vehicles['b'], 'released', self::D3 . ' 11:00:00', null);

        $this->addTransition($vehicles['c'], 'deposited', self::D2 . ' 09:00:00', null);
        $this->addTransition($vehicles['d'], 'deposited', self::D3 . ' 12:00:00', null);
    }

    private function addTransition(int $orderId, string $slug, string $changedAt, ?string $endedAt): void
    {
        DB::connection('commerce')->table('order_transitions_history')->insert([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => 0,
            'order_id' => $orderId,
            'to_status_id' => $this->statusIds[$slug],
            'changed_at' => $changedAt,
            'ended_at' => $endedAt,
            'is_current' => $endedAt === null,
            'is_deleted' => 0,
        ]);
    }
}
