<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class RunWorkflowFromEntityTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

    public function testRunActivityDirectlyWithNoEntityId(): void
    {
        $response = $this->graphQL('
            mutation($input: runWorkflowEntityInput!) {
                runWorkflowFromEntity(input: $input)
            }
        ', [
            'input' => [
                'entity_namespace' => 'Kanvas\\Inventory\\Products\\Models\\Products',
                'entity_id' => '0',
                'action' => 'created',
                'activity_class' => 'Kanvas\\Connectors\\Internal\\Activities\\UnPublishExpiredProductActivity',
            ],
        ], [], $this->getAppKeyHeader());

        $response->assertSuccessful()
            ->assertGraphQLErrorFree();

        $data = $response->json('data.runWorkflowFromEntity');
        $this->assertNotNull($data);
        $this->assertArrayHasKey('product', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals(0, $data['product']);
        $this->assertEquals('unpublished', $data['status']);
    }

    public function testRunActivityFailsWithoutActivityClass(): void
    {
        $response = $this->graphQL('
            mutation($input: runWorkflowEntityInput!) {
                runWorkflowFromEntity(input: $input)
            }
        ', [
            'input' => [
                'entity_namespace' => 'Kanvas\\Inventory\\Products\\Models\\Products',
                'entity_id' => '0',
                'action' => 'created',
            ],
        ], [], $this->getAppKeyHeader());

        $this->assertNotNull($response->json('errors'));
    }
}
