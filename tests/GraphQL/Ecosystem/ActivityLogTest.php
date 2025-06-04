<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    /**
     * Test Create Activity Log
     */
    public function testCreateActivityLog(): void
    {
        $logName = 'linkedfield';
        $description = 'none';
        $properties = ['hole'];

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(get_class($company), $app);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation createActivityLog($input: ActivityLogInput!) {
                createActivityLog(input: $input) {
                    id
                    log_name
                    description
                    subject_type
                    event
                    entity_id
                    causer_id
                    properties
                    created_at
                }
            }
        ', [
            'input' => [
                'system_module_uuid' => $systemModule->uuid,
                'entity_id' => $company->getKey(),
                'log_name' => $logName,
                'description' => $description,
                'properties' => $properties,
            ],
        ])->assertJson([
            'data' => [
                'createActivityLog' => [
                    'log_name' => $logName,
                    'description' => $description,
                    'entity_id' => $company->getKey(),
                    'properties' => $properties,
                ],
            ],
        ]);

        $this->assertNotNull($response->json('data.createActivityLog.id'));
        $this->assertNotNull($response->json('data.createActivityLog.created_at'));
    }

    /**
     * Test Get Activity Log
     */
    public function testGetActivityLog(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(get_class($company), $app);

        $response = $this->graphQL(/** @lang GraphQL */ '
            query getActivityLog($system_module_uuid: String!, $entity_id: Int!, $first: Int) {
                getActivityLog(
                    system_module_uuid: $system_module_uuid
                    entity_id: $entity_id
                    first: $first
                ) {
                    data {
                        id
                        log_name
                        description
                        subject_type
                        event
                        entity_id
                        causer_id
                        properties
                        created_at
                    }
                }
            }
        ', [
            'system_module_uuid' => $systemModule->uuid,
            'entity_id' => $company->getKey(),
            'first' => 3,
        ]);

        $this->assertArrayHasKey('data', $response->json());
    }
}
