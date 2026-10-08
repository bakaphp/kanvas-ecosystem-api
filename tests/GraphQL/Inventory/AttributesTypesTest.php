<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;
use Tests\TestCase;
use Tests\Traits\AssertsIsDefaultOrdering;

class AttributesTypesTest extends TestCase
{
    use AssertsIsDefaultOrdering;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    /**
     * testCreate.
     */
    // public function testCreate(): void
    // {
    //     $data = [
    //         'name' => fake()->name
    //     ];

    //     $response = $this->graphQL('
    //         mutation($data: AttributesTypeInput!) {
    //             createAttributeType(input: $data)
    //             {
    //                 name
    //             }
    //         }', ['data' => $data]);

    //     $this->assertArrayHasKey('name', $response->json()['data']['createAttributeType']);
    // }

    /**
     * testSearch.
     */
    public function testSearch(): void
    {
        $response = $this->graphQL('
            query {
                attributesTypes {
                    data {
                        name
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['attributesTypes']['data'][0]);
    }

    /**
     * testUpdate.
     */
    // public function testUpdate(): void
    // {
    //     $data = [
    //         'name' => fake()->name,
    //     ];
    //     $response = $this->graphQL('
    //         mutation($data: AttributesTypeInput!) {
    //             createAttributeType(input: $data)
    //             {
    //                 id
    //                 name
    //             }
    //         }', ['data' => $data]);

    //     $this->assertArrayHasKey('name', $response->json()['data']['createAttributeType']);

    //     $id = $response->json()['data']['createAttributeType']['id'];
    //     $dataUpdate = [
    //         'name' => fake()->name
    //     ];

    //     $response = $this->graphQL('
    //         mutation($dataUpdate: AttributeTypeUpdateInput! $id: ID!) {
    //             updateAttributeType(input: $dataUpdate id: $id)
    //             {
    //                 name
    //             }
    //         }', ['dataUpdate' => $dataUpdate, 'id' => $id]);

    //     $this->assertEquals(
    //         $dataUpdate['name'],
    //         $response->json()['data']['updateAttributeType']['name']
    //     );
    // }

    /**
     * testDelete.
     */
    // public function testDelete(): void
    // {
    //     $data = [
    //         'name' => fake()->name
    //     ];
    //     $response = $this->graphQL('
    //         mutation($data: AttributesTypeInput!) {
    //             createAttributeType(input: $data)
    //             {
    //                 id
    //                 name
    //             }
    //         }', ['data' => $data])->json()['data']['createAttributeType'];

    //     $this->assertArrayHasKey('name', $response);

    //     $id = $response['id'];
    //     $this->graphQL('
    //         mutation($id: ID!) {
    //             deleteAttributeType(id: $id)
    //         }', ['id' => $id])->assertJson([
    //         'data' => ['deleteAttributeType' => true]
    //     ]);
    // }

    public function testAttributesTypesOrdersByIsDefault(): void
    {
        [$defaultId, $nonDefaultId] = $this->createDefaultThenNonDefaultTypeIds();

        $query = '
            query($ids: Mixed!, $order: SortOrder!) {
                attributesTypes(
                    where: {column: ID, operator: IN, value: $ids}
                    orderBy: [{column: IS_DEFAULT, order: $order}]
                ) {
                    data { id is_default }
                }
            }
        ';

        $this->assertOrdersByIsDefault(
            $query,
            'data.attributesTypes.data',
            $defaultId,
            $nonDefaultId
        );
    }

    public function testAttributesTypesFiltersByIsDefault(): void
    {
        [$defaultId, $nonDefaultId] = $this->createDefaultThenNonDefaultTypeIds();

        $query = '
            query($value: Mixed!, $ids: Mixed!) {
                attributesTypes(
                    where: {AND: [
                        {column: ID, operator: IN, value: $ids}
                        {column: IS_DEFAULT, operator: EQ, value: $value}
                    ]}
                ) {
                    data { id is_default }
                }
            }
        ';

        $this->assertFiltersByIsDefault(
            $query,
            'data.attributesTypes.data',
            $defaultId,
            $nonDefaultId
        );
    }

    /**
     * @return array{0: int, 1: int} [default type id, non-default type id created after it]
     */
    private function createDefaultThenNonDefaultTypeIds(): array
    {
        $suffix = Str::uuid()->toString();

        $default = $this->createGlobalType('Default Sort Type ' . $suffix, true);
        $nonDefault = $this->createGlobalType('Plain Sort Type ' . $suffix, false);

        return [$default->getId(), $nonDefault->getId()];
    }

    private function createGlobalType(string $name, bool $isDefault): AttributesTypes
    {
        $user = auth()->user();

        return AttributesTypes::create([
            'apps_id' => 0,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_default' => $isDefault,
        ]);
    }
}
