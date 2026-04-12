<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\TestCase;

class OrderTypeStatusesTest extends TestCase
{
    protected $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testListOrderTypeWithPaginatedStatuses(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'statuses-test-type-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'draft',
            'name' => 'Draft',
            'is_default' => true,
            'is_final' => false,
            'sequence' => 1,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'pending',
            'name' => 'Pending',
            'is_default' => false,
            'is_final' => false,
            'sequence' => 2,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'completed',
            'name' => 'Completed',
            'is_default' => false,
            'is_final' => true,
            'sequence' => 3,
        ]);

        $response = $this->graphQL('
            query($where: QueryOrderTypesWhereWhereConditions!) {
                orderTypes(where: $where) {
                    data {
                        id
                        name
                        statuses(orderBy: { column: SEQUENCE, order: ASC }, first: 25) {
                            data {
                                id
                                name
                                slug
                                is_default
                                is_final
                                sequence
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $orderType->id,
            ],
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.orderTypes.data.0');
        $this->assertNotNull($data);
        $this->assertArrayHasKey('statuses', $data);

        $statuses = $data['statuses']['data'];
        $this->assertNotEmpty($statuses);
        $this->assertGreaterThanOrEqual(3, count($statuses));

        $this->assertArrayHasKey('id', $statuses[0]);
        $this->assertArrayHasKey('name', $statuses[0]);
        $this->assertArrayHasKey('slug', $statuses[0]);
        $this->assertArrayHasKey('is_default', $statuses[0]);
        $this->assertArrayHasKey('is_final', $statuses[0]);
        $this->assertArrayHasKey('sequence', $statuses[0]);

        $paginatorInfo = $data['statuses']['paginatorInfo'];
        $this->assertGreaterThanOrEqual(3, $paginatorInfo['total']);

        $sequences = array_column($statuses, 'sequence');
        $sortedSequences = $sequences;
        sort($sortedSequences);
        $this->assertEquals($sortedSequences, $sequences);
    }

    public function testOrderTypeStatusesOrderBySequenceDesc(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'statuses-order-test-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'first',
            'name' => 'First',
            'is_default' => true,
            'is_final' => false,
            'sequence' => 1,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'last',
            'name' => 'Last',
            'is_default' => false,
            'is_final' => true,
            'sequence' => 10,
        ]);

        $response = $this->graphQL('
            query($where: QueryOrderTypesWhereWhereConditions!) {
                orderTypes(where: $where) {
                    data {
                        id
                        statuses(orderBy: { column: SEQUENCE, order: DESC }, first: 25) {
                            data {
                                slug
                                sequence
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $orderType->id,
            ],
        ]);

        $response->assertSuccessful();
        $statuses = $response->json('data.orderTypes.data.0.statuses.data');
        $this->assertNotEmpty($statuses);
        $this->assertEquals('last', $statuses[0]['slug']);
    }
}
