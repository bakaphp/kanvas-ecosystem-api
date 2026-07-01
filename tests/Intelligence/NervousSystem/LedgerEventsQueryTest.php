<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class LedgerEventsQueryTest extends TestCase
{
    public function testLedgerEventsQueryReturnsRowsForTheCurrentApp(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'graphql-test-' . uniqid();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['marker' => 'visible'],
            ),
        )->execute();

        $response = $this->graphQL(
            '
            query LedgerEvents($where: QueryLedgerEventsWhereWhereConditions) {
                ledgerEvents(first: 50, where: $where) {
                    data {
                        uuid
                        source_domain
                        event_type
                        status
                        payload
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
            ',
            [
                'where' => [
                    'column' => 'EVENT_TYPE',
                    'operator' => 'EQ',
                    'value' => $tag,
                ],
            ],
        );

        $response->assertSuccessful();

        $events = $response->json('data.ledgerEvents.data');
        $this->assertNotEmpty($events);
        $this->assertSame($tag, $events[0]['event_type']);
        $this->assertSame('TestDomain', $events[0]['source_domain']);
        $this->assertSame('info', $events[0]['status']);
        $this->assertSame(['marker' => 'visible'], $events[0]['payload']);
    }

    public function testChangeCountFilterReturnsOnlyChangeBearingEvents(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'changecount-' . uniqid();

        // One real change-bearing event...
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['changed_fields' => ['title', 'email_changed'], 'changes' => ['title' => ['from' => 'a', 'to' => 'b']]],
            ),
        )->execute();

        // ...and one empty event under the same tag — the feed must not return it.
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['changed_fields' => [], 'changes' => []],
            ),
        )->execute();

        $response = $this->graphQL(
            '
            query LedgerEvents($where: QueryLedgerEventsWhereWhereConditions) {
                ledgerEvents(first: 50, where: $where) {
                    data { event_type change_count }
                    paginatorInfo { total }
                }
            }
            ',
            [
                'where' => [
                    'AND' => [
                        ['column' => 'EVENT_TYPE', 'operator' => 'EQ', 'value' => $tag],
                        ['column' => 'CHANGE_COUNT', 'operator' => 'GT', 'value' => 0],
                    ],
                ],
            ],
        );

        $response->assertSuccessful();

        $events = $response->json('data.ledgerEvents.data');
        $this->assertCount(1, $events, 'Only the change-bearing event passes the change_count filter.');
        $this->assertSame(2, $events[0]['change_count']);
    }

    public function testCountMaterialChangesOnlyCountsBeforeAfterEntries(): void
    {
        $this->assertSame(0, Event::countMaterialChanges(['new_account' => true, 'location_added' => true]));
        $this->assertSame(0, Event::countMaterialChanges(['contacts_added' => ['5:http://x']]));
        $this->assertSame(0, Event::countMaterialChanges(null));
        $this->assertSame(0, Event::countMaterialChanges([]));
        $this->assertSame(1, Event::countMaterialChanges(['title' => ['from' => 'a', 'to' => 'b'], 'new_account' => true]));
        $this->assertSame(2, Event::countMaterialChanges([
            'title' => ['from' => 'a', 'to' => 'b'],
            'current_employer' => ['from' => 'x', 'to' => 'y'],
            'new_account' => true,
        ]));
    }

    public function testMaterialChangeCountFilterExcludesFlagOnlyEvents(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'material-' . uniqid();

        // Flag-only event (change_count 1, material 0) — must not pass the material filter.
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['changed_fields' => ['new_account'], 'changes' => ['new_account' => true]],
            ),
        )->execute();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: [
                    'changed_fields' => ['title', 'new_account'],
                    'changes' => ['title' => ['from' => 'a', 'to' => 'b'], 'new_account' => true],
                ],
            ),
        )->execute();

        $response = $this->graphQL(
            '
            query LedgerEvents($where: QueryLedgerEventsWhereWhereConditions) {
                ledgerEvents(first: 50, where: $where) {
                    data { change_count material_change_count }
                    paginatorInfo { total }
                }
            }
            ',
            [
                'where' => [
                    'AND' => [
                        ['column' => 'EVENT_TYPE', 'operator' => 'EQ', 'value' => $tag],
                        ['column' => 'MATERIAL_CHANGE_COUNT', 'operator' => 'GT', 'value' => 0],
                    ],
                ],
            ],
        );

        $response->assertSuccessful();

        $events = $response->json('data.ledgerEvents.data');
        $this->assertCount(1, $events, 'Flag-only event is excluded; only the real before/after event passes.');
        $this->assertSame(1, $events[0]['material_change_count']);
        $this->assertSame(2, $events[0]['change_count'], 'change_count still counts the flag; material does not.');
    }

    public function testLedgerEventsQueryDoesNotLeakAcrossApps(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $myTag = 'mine-' . uniqid();
        $otherTag = 'other-' . uniqid();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $myTag,
            ),
        )->execute();

        // Foreign-tenant event inserted directly to bypass DTO model lookups.
        \Kanvas\NervousSystem\Ledger\Models\Event::query()->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'apps_id' => 999998,
            'companies_id' => 999998,
            'source_domain' => 'TestDomain',
            'event_type' => $otherTag,
            'status' => 'info',
            'occurred_at' => now(),
            'indexed_at' => now(),
        ]);

        $response = $this->graphQL(
            '
            query LedgerEvents($where: QueryLedgerEventsWhereWhereConditions) {
                ledgerEvents(first: 50, where: $where) {
                    data { event_type }
                }
            }
            ',
            [
                'where' => [
                    'AND' => [
                        ['column' => 'EVENT_TYPE', 'operator' => 'IN', 'value' => [$myTag, $otherTag]],
                    ],
                ],
            ],
        );

        $response->assertSuccessful();

        $events = $response->json('data.ledgerEvents.data');
        $eventTypes = array_column($events, 'event_type');

        $this->assertContains($myTag, $eventTypes);
        $this->assertNotContains(
            $otherTag,
            $eventTypes,
            'GraphQL query must not leak events from a different apps_id',
        );
    }
}
