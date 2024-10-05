<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Tests\TestCase;

class EventTest extends TestCase
{
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
}
