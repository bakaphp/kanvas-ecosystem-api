<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsQuery;
use Kanvas\NervousSystem\Ledger\Enums\EventGroupByEnum;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Services\EventAnalyticsService;
use Tests\TestCase;

final class EventAnalyticsServiceTest extends TestCase
{
    private const string MARKER = 'analytics-test-marker';

    protected function tearDown(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('source_domain', self::MARKER)
            ->delete();

        parent::tearDown();
    }

    public function test_counts_distinct_payload_keys_and_grouping(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        // entity 1001 appears twice → 4 enriched events, 3 distinct entities.
        $this->seedEvent('people.enriched', 1001, ['changes' => ['current_employer' => ['from' => 'A', 'to' => 'B'], 'title' => ['from' => 'x', 'to' => 'y']]], '2026-06-10 12:00:00');
        $this->seedEvent('people.enriched', 1002, ['changes' => ['current_employer' => ['from' => 'C', 'to' => 'D']]], '2026-06-11 12:00:00');
        $this->seedEvent('people.enriched', 1003, ['changes' => ['title' => ['from' => 'x', 'to' => 'z']]], '2026-06-12 12:00:00');
        $this->seedEvent('people.enriched', 1001, ['changes' => ['current_employer' => ['from' => 'B', 'to' => 'E']]], '2026-06-13 12:00:00');
        $this->seedEvent('people.other', 1004, ['changes' => ['title' => ['from' => 'q', 'to' => 'r']]], '2026-06-14 12:00:00');

        $service = new EventAnalyticsService();

        $enriched = $this->analyticsQuery($app, $company, ['people.enriched']);

        $this->assertSame(4, $service->count($enriched), 'Four people.enriched events.');
        $this->assertSame(3, $service->countDistinctEntities($enriched), 'Three distinct entities (1001 counted once).');

        $employerOnly = $this->analyticsQuery($app, $company, ['people.enriched'], ['changes.current_employer']);
        $this->assertSame(3, $service->count($employerOnly), 'Three events carry changes.current_employer.');
        $this->assertSame(2, $service->countDistinctEntities($employerOnly), 'Across two distinct entities.');

        $byKey = $service->countByPayloadKeys($enriched, ['changes.current_employer', 'changes.title'])
            ->keyBy('key');
        $this->assertSame(3, $byKey['changes.current_employer']->count);
        $this->assertSame(2, $byKey['changes.title']->count);

        $byType = $service->groupBy($this->analyticsQuery($app, $company), EventGroupByEnum::EVENT_TYPE)
            ->keyBy('key');
        $this->assertSame(4, $byType['people.enriched']->count);
        $this->assertSame(1, $byType['people.other']->count);
    }

    public function test_respects_date_range(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $this->seedEvent('people.enriched', 2001, ['changes' => ['title' => ['from' => 'a', 'to' => 'b']]], '2026-06-10 12:00:00');
        $this->seedEvent('people.enriched', 2002, ['changes' => ['title' => ['from' => 'a', 'to' => 'b']]], '2026-06-11 12:00:00');
        $this->seedEvent('people.enriched', 2003, ['changes' => ['title' => ['from' => 'a', 'to' => 'b']]], '2026-06-12 12:00:00');

        $service = new EventAnalyticsService();

        $windowed = new EventAnalyticsQuery(
            app: $app,
            company: $company,
            eventTypes: ['people.enriched'],
            sourceDomain: self::MARKER,
            from: Carbon::parse('2026-06-11 00:00:00'),
            to: Carbon::parse('2026-06-12 23:59:59'),
        );

        $this->assertSame(2, $service->count($windowed), 'Only the two events inside the window.');
    }

    public function test_scopes_to_app(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $this->seedEvent('people.enriched', 3001, ['changes' => ['title' => ['from' => 'a', 'to' => 'b']]], '2026-06-10 12:00:00');

        $otherApp = Apps::query()
            ->where('id', '!=', $app->getId())
            ->where('is_deleted', 0)
            ->first();

        if ($otherApp !== null) {
            new AppendEventAction(
                new EventData(
                    app: $otherApp,
                    company: $company,
                    sourceDomain: self::MARKER,
                    eventType: 'people.enriched',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: 'AnalyticsTestEntity',
                    sourceEntityId: 3999,
                    payload: ['changes' => ['title' => ['from' => 'a', 'to' => 'b']]],
                    occurredAt: Carbon::parse('2026-06-10 12:00:00'),
                ),
            )->execute();
        }

        $count = new EventAnalyticsService()->count($this->analyticsQuery($app, $company, ['people.enriched']));

        $this->assertSame(1, $count, 'Only this app\'s event is counted, never the other app\'s.');
    }

    private function analyticsQuery(Apps $app, Companies $company, array $eventTypes = [], array $payloadHasKeys = []): EventAnalyticsQuery
    {
        return new EventAnalyticsQuery(
            app: $app,
            company: $company,
            eventTypes: $eventTypes,
            sourceDomain: self::MARKER,
            payloadHasKeys: $payloadHasKeys,
        );
    }

    private function seedEvent(string $eventType, int $entityId, array $payload, string $occurredAt): void
    {
        new AppendEventAction(
            new EventData(
                app: app(Apps::class),
                company: static::$cachedUser->getCurrentCompany(),
                sourceDomain: self::MARKER,
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: 'AnalyticsTestEntity',
                sourceEntityId: $entityId,
                actorType: 'System',
                payload: $payload,
                occurredAt: Carbon::parse($occurredAt),
            ),
        )->execute();
    }
}
