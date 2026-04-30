<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
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
                appsId: $app->getId(),
                companiesId: $company->getId(),
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

    public function testLedgerEventsQueryDoesNotLeakAcrossApps(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $myTag = 'mine-' . uniqid();
        $otherTag = 'other-' . uniqid();

        new AppendEventAction(
            new EventData(
                appsId: $app->getId(),
                companiesId: $company->getId(),
                sourceDomain: 'TestDomain',
                eventType: $myTag,
            ),
        )->execute();

        new AppendEventAction(
            new EventData(
                appsId: 999998,
                companiesId: 999998,
                sourceDomain: 'TestDomain',
                eventType: $otherTag,
            ),
        )->execute();

        $response = $this->graphQL(
            '
            query LedgerEvents($where: QueryLedgerEventsWhereWhereConditions) {
                ledgerEvents(first: 50, where: $where) {
                    data { event_type apps_id }
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

        foreach ($events as $event) {
            $this->assertSame($app->getId(), $event['apps_id']);
        }
    }
}
