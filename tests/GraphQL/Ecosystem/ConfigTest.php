<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Companies\Models\Companies;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function testSetConfig(): void
    {
        $company = Companies::inRandomOrder()->first();
        $this->graphQL( /** @lang GraphQL */
            '
            mutation($input: ModuleConfigInput!) {
                setConfig(input: $input)
            }',
            [
               'input' => [
                   'module' => 'COMPANIES',
                   'entity_uuid' => $company->uuid,
                   'key' => 'test',
                   'value' => 'test',
               ],
            ]
        )
        ->assertSuccessful()
        ->assertJson([
           'data' => [
               'setConfig' => true,
           ],
        ]);
    }

    public function testConfig(): void
    {
        $company = Companies::inRandomOrder()->first();
        $this->graphQL( /** @lang GraphQL */
            '
           mutation($input: ModuleConfigInput!) {
               setConfig(input: $input)
           }',
            [
               'input' => [
                   'module' => 'COMPANIES',
                   'entity_uuid' => $company->uuid,
                   'key' => 'test',
                   'value' => 'test',
               ],
           ]
        )
       ->assertSuccessful()
       ->assertJson([
           'data' => [
               'setConfig' => true,
           ],
       ]);
        $this->graphQL( /** @lang GraphQL */
            '
            query($module: ModulesConfig!, $entity_uuid: String!) {
                config(module: $module, entity_uuid: $entity_uuid) {
                    key
                    value
                    module
                    entity_uuid
                }
            }',
            [
               'module' => 'COMPANIES',
               'entity_uuid' => $company->uuid,
            ]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'config' => [
                    [
                        'key' => 'test',
                        'value' => 'test',
                        'module' => 'COMPANIES',
                        'entity_uuid' => $company->uuid,
                    ],
                ],
            ],
        ]);
    }
}
