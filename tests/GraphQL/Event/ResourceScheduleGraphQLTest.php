<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ResourceScheduleGraphQLTest extends TestCase
{
    use InventoryCases;

    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $product;
    protected $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'event_slot', 'value' => 100],
        ])->json()['data']['createProduct'];

        $this->product = Products::find($productResponse['id']);
        $this->variantId = $this->product->variants()->first()->id;

        $this->addVariantToChannel(
            variantId: (string) $this->variantId,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $this->variantId,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $setup = new Setup($this->apps, $this->user, $this->company);
        $setup->run();
    }

    public function testSetResourceScheduleMutation(): void
    {
        Queue::fake();

        $input = [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'schedule_type' => 'WEEKLY',
            'days' => [
                ['day' => 'monday', 'active' => true, 'open' => '08:00 AM', 'close' => '06:00 PM'],
                ['day' => 'tuesday', 'active' => true, 'open' => '08:00 AM', 'close' => '06:00 PM'],
                ['day' => 'wednesday', 'active' => false],
                ['day' => 'thursday', 'active' => false],
                ['day' => 'friday', 'active' => true, 'open' => '09:00 AM', 'close' => '05:00 PM'],
                ['day' => 'saturday', 'active' => false],
                ['day' => 'sunday', 'active' => false],
            ],
        ];

        $response = $this->graphQL('
            mutation setResourceSchedule($input: ResourceScheduleInput!) {
                setResourceSchedule(input: $input) {
                    is_configured
                    schedule_type
                    days_count
                    days {
                        day
                        active
                        open
                        close
                    }
                    rules {
                        id
                        resources_id
                    }
                    exceptions {
                        id
                    }
                }
            }
        ', ['input' => $input]);

        $response->assertSuccessful();

        $data = $response->json('data.setResourceSchedule');
        $this->assertTrue($data['is_configured']);
        $this->assertEquals('WEEKLY', $data['schedule_type']);
        $this->assertEquals(3, $data['days_count']);
        $this->assertCount(7, $data['days']);
    }

    public function testQueryResourceSchedule(): void
    {
        Queue::fake();

        $this->setScheduleViaGraphQL();

        $response = $this->graphQL('
            query resourceSchedule($resources_id: ID!, $resources_type: String!) {
                resourceSchedule(resources_id: $resources_id, resources_type: $resources_type) {
                    is_configured
                    schedule_type
                    days_count
                    days {
                        day
                        active
                        open
                        close
                    }
                    last_modified
                    rules {
                        id
                    }
                    exceptions {
                        id
                    }
                }
            }
        ', [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.resourceSchedule');
        $this->assertTrue($data['is_configured']);
        $this->assertCount(7, $data['days']);
        $this->assertNotNull($data['last_modified']);
    }

    public function testIsResourceOpenQuery(): void
    {
        Queue::fake();

        $this->setScheduleViaGraphQL();

        $monday = Carbon::parse('next monday 10:00')->format('Y-m-d H:i:s');

        $response = $this->graphQL('
            query isResourceOpen($resources_id: ID!, $resources_type: String!, $datetime: DateTime) {
                isResourceOpen(resources_id: $resources_id, resources_type: $resources_type, datetime: $datetime)
            }
        ', [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'datetime' => $monday,
        ]);

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.isResourceOpen'));
    }

    public function testCreateScheduleException(): void
    {
        $windowStart = Carbon::parse('next monday 00:00:00')->format('Y-m-d H:i:s');
        $windowEnd = Carbon::parse('next monday 23:59:59')->format('Y-m-d H:i:s');

        $input = [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'kind' => 'BLACKOUT',
            'reason' => 'Holiday closure',
        ];

        $response = $this->graphQL('
            mutation createScheduleException($input: ScheduleExceptionInput!) {
                createScheduleException(input: $input) {
                    id
                    resources_id
                    resources_type
                    window_start
                    window_end
                    kind
                    reason
                }
            }
        ', ['input' => $input]);

        $response->assertSuccessful();

        $data = $response->json('data.createScheduleException');
        $this->assertEquals('blackout', $data['kind']);
        $this->assertEquals('Holiday closure', $data['reason']);
    }

    public function testDeleteScheduleException(): void
    {
        $exception = ScheduleException::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->product->getId(),
            'resources_type' => $this->product->getMorphClass(),
            'kind' => 'blackout',
            'window_start' => Carbon::parse('next monday 00:00:00'),
            'window_end' => Carbon::parse('next monday 23:59:59'),
            'reason' => 'To be deleted',
        ]);

        $response = $this->graphQL('
            mutation deleteScheduleException($id: ID!) {
                deleteScheduleException(id: $id)
            }
        ', ['id' => $exception->id]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertTrue($response->json('data.deleteScheduleException'));

        $this->assertDatabaseHas('schedule_exceptions', [
            'id' => $exception->id,
            'is_deleted' => 1,
        ], 'event');
    }

    public function testOverlappingExceptionRejected(): void
    {
        $windowStart = Carbon::parse('next monday 00:00:00')->format('Y-m-d H:i:s');
        $windowEnd = Carbon::parse('next monday 23:59:59')->format('Y-m-d H:i:s');

        $input = [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'kind' => 'BLACKOUT',
            'reason' => 'First exception',
        ];

        $this->graphQL('
            mutation createScheduleException($input: ScheduleExceptionInput!) {
                createScheduleException(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $overlappingInput = [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'window_start' => Carbon::parse('next monday 12:00:00')->format('Y-m-d H:i:s'),
            'window_end' => Carbon::parse('next monday 18:00:00')->format('Y-m-d H:i:s'),
            'kind' => 'BLACKOUT',
            'reason' => 'Overlapping exception',
        ];

        $response = $this->graphQL('
            mutation createScheduleException($input: ScheduleExceptionInput!) {
                createScheduleException(input: $input) { id }
            }
        ', ['input' => $overlappingInput]);

        $this->assertNotNull($response->json('errors'));
    }

    private function setScheduleViaGraphQL(): void
    {
        $input = [
            'resources_id' => $this->product->getId(),
            'resources_type' => 'product',
            'schedule_type' => 'WEEKLY',
            'days' => [
                ['day' => 'monday', 'active' => true, 'open' => '08:00 AM', 'close' => '06:00 PM'],
                ['day' => 'tuesday', 'active' => true, 'open' => '08:00 AM', 'close' => '06:00 PM'],
                ['day' => 'wednesday', 'active' => false],
                ['day' => 'thursday', 'active' => false],
                ['day' => 'friday', 'active' => true, 'open' => '09:00 AM', 'close' => '05:00 PM'],
                ['day' => 'saturday', 'active' => false],
                ['day' => 'sunday', 'active' => false],
            ],
        ];

        $this->graphQL('
            mutation setResourceSchedule($input: ResourceScheduleInput!) {
                setResourceSchedule(input: $input) {
                    is_configured
                }
            }
        ', ['input' => $input])->assertSuccessful();
    }
}
