<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Tests\TestCase;

class EventTest extends TestCase
{
    public function testTypesenseSchemaIdIsString(): void
    {
        $schema = new Event()->typesenseCollectionSchema();
        $idField = collect($schema['fields'])->firstWhere('name', 'id');
        $this->assertNotNull($idField);
        $this->assertSame('string', $idField['type'], 'Typesense requires the document id field to be a string');
    }

    public function testCreateEvent()
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /**
         * @todo move to use factory
         */
        $setup = new Setup($app, $user, $company);
        $setup->run();

        $input = [
            'name' => 'Test Event',
            'description' => 'Test event description',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '11:00',
                    'end_time' => '23:00',
                ],
            ],
        ];

        $this->graphQL('
        mutation($input: EventInput!) {
            createEvent(input: $input) {
                id
                name
            }
        }
    ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createEvent' => [
                    'name' => 'Test Event',
                ],
            ],
        ]);
    }

    public function testUpdateEventDates(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $input = [
            'name' => 'Event To Update',
            'description' => 'Original description',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                ],
            ],
        ];

        $createResponse = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $eventId = $createResponse->json('data.createEvent.id');

        $newDate = date('Y-m-d', strtotime('+7 days'));

        $this->graphQL('
            mutation($id: ID!, $input: EventUpdateInput!) {
                updateEvent(id: $id, input: $input) {
                    id
                    versions {
                        data {
                            dates {
                                date
                                start_time
                                end_time
                            }
                        }
                    }
                }
            }
        ', [
            'id' => $eventId,
            'input' => [
                'dates' => [
                    [
                        'date' => $newDate,
                        'start_time' => '14:00',
                        'end_time' => '16:00',
                    ],
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateEvent' => [
                    'versions' => [
                        'data' => [
                            [
                                'dates' => [
                                    [
                                        'date' => $newDate,
                                        'start_time' => '14:00:00',
                                        'end_time' => '16:00:00',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGetEvent(): void
    {
        $this->graphQL('
            query {
                events {
                    data {
                        id,
                        name,
                        description,
                        versions {
                            data {
                                id
                                dates {
                                    date,
                                    start_time,
                                    end_time
                                }
                            }
                        }
                    }
                }
            }')->assertSee('events');
    }

    public function testGetEventVersion(): void
    {
        $this->graphQL('
            query {
                eventVersions {
                    data {
                        id,
                        name,
                        description,
                        dates {
                            date,
                            start_time,
                            end_time
                        }
                    }
                }
            }')->assertSee('eventVersions');
    }

    public function testCreateEventDoesNotDuplicateEventOnSameSlug(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $eventName = 'Reused Event ' . uniqid();
        $input = [
            'name' => $eventName,
            'description' => 'Tests Event-level upsert',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '08:00',
                    'end_time' => '09:00',
                ],
            ],
        ];

        $mutation = '
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    slug
                }
            }
        ';

        $first = $this->graphQL($mutation, ['input' => $input])->assertSuccessful();
        $second = $this->graphQL($mutation, ['input' => $input])->assertSuccessful();
        $third = $this->graphQL($mutation, ['input' => $input])->assertSuccessful();

        $eventId = $first->json('data.createEvent.id');
        $slug = $first->json('data.createEvent.slug');

        $this->assertSame($eventId, $second->json('data.createEvent.id'));
        $this->assertSame($eventId, $third->json('data.createEvent.id'));
        $this->assertSame($slug, $second->json('data.createEvent.slug'));
        $this->assertSame($slug, $third->json('data.createEvent.slug'));

        $this->assertSame(
            1,
            Event::where('slug', $slug)
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->count(),
            'Event::updateOrCreate must reuse the existing row, not insert duplicates',
        );
    }

    public function testCreateEventCreatesVersionedSlugOnDuplicate(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $input = [
            'name' => 'Duplicate Slug Event ' . uniqid(),
            'description' => 'Tests slug versioning on duplicate booking',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '09:00',
                    'end_time' => '10:00',
                ],
            ],
        ];

        $mutation = '
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    versions {
                        data {
                            slug
                            version
                        }
                    }
                }
            }
        ';

        $firstEventId = $this->graphQL($mutation, ['input' => $input])
            ->assertSuccessful()
            ->json('data.createEvent.id');

        $secondResponse = $this->graphQL($mutation, ['input' => $input])->assertSuccessful();

        $this->assertSame($firstEventId, $secondResponse->json('data.createEvent.id'));

        $versions = $secondResponse->json('data.createEvent.versions.data');
        $this->assertGreaterThanOrEqual(2, count($versions));

        $slugs = array_column($versions, 'slug');
        $versionNumbers = array_map('intval', array_column($versions, 'version'));

        $this->assertContains(1, $versionNumbers);
        $this->assertContains(2, $versionNumbers);

        sort($slugs);
        $this->assertSame($slugs[0] . '-2', $slugs[1]);
    }

    public function testCreateEventWithConfig(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $input = [
            'name' => 'Test Event With Config',
            'description' => 'Test event with config field',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'config' => [
                'setting1' => 'value1',
                'setting2' => true,
                'nested' => [
                    'key' => 'nestedValue',
                ],
            ],
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                ],
            ],
        ];

        $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    name
                    resources {
                        id
                        metadata
                    }
                }
            }
        ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createEvent' => [
                    'name' => 'Test Event With Config',
                ],
            ],
        ]);
    }

    public function testCreateEventWithTagsAndCustomFields(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $input = [
            'name' => 'Tagged Event ' . uniqid(),
            'description' => 'Event with tags and custom fields',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                ],
            ],
            'tags' => [['name' => 'featured'], ['name' => 'vip']],
            'custom_fields' => [
                ['name' => 'internal_code', 'data' => 'EVT-2026'],
                ['name' => 'sponsor', 'data' => 'AcmeCorp'],
            ],
        ];

        $r = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    name
                    tags { data { name } }
                    custom_fields { data { name } }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $tagNames = array_column($r->json('data.createEvent.tags.data'), 'name');
        $this->assertContains('featured', $tagNames);
        $this->assertContains('vip', $tagNames);

        $cfNames = array_column($r->json('data.createEvent.custom_fields.data'), 'name');
        $this->assertContains('internal_code', $cfNames);
        $this->assertContains('sponsor', $cfNames);
    }

    public function testFollowAndUnfollowEvent(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $createResponse = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id }
            }
        ', [
            'input' => [
                'name' => 'Followable Event ' . uniqid(),
                'description' => 'Test follow',
                'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
                'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
                'dates' => [
                    [
                        'date' => date('Y-m-d'),
                        'start_time' => '10:00',
                        'end_time' => '12:00',
                    ],
                ],
            ],
        ])->assertSuccessful();

        $eventId = $createResponse->json('data.createEvent.id');
        $event = Event::find($eventId);

        $follow = $this->graphQL('
            mutation($input: FollowInput!) {
                followEvent(input: $input)
            }
        ', [
            'input' => [
                'user_id' => $user->getId(),
                'entity_id' => $event->uuid,
            ],
        ])->assertSuccessful();

        $this->assertTrue($follow->json('data.followEvent'));

        $unfollow = $this->graphQL('
            mutation($input: FollowInput!) {
                unFollowEvent(input: $input)
            }
        ', [
            'input' => [
                'user_id' => $user->getId(),
                'entity_id' => $event->uuid,
            ],
        ])->assertSuccessful();

        $this->assertTrue($unfollow->json('data.unFollowEvent'));
    }
}
