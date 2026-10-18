<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CustomFieldsTest extends TestCase
{
    public function testSetCustomField(): void
    {
        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => fake()->numberBetween(1, 100),
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertJson([
            'data' => [
                'setCustomField' => true,
            ],
        ]);
    }

    /**
     * @deprecated
     */
    public function testGetCustomField(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                getCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertSee($value);
    }

    public function testGetCustomFieldQuery(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            query customField( 
            $name : String!, 
            $system_module_uuid: String! , 
            $entity_id : String!) {
                customField(
                    name: $name, 
                    system_module_uuid: $system_module_uuid,
                    entity_id : $entity_id
                )
            }',
            [
            'name' => $key,
            'system_module_uuid' => get_class(auth()->user()),
            'entity_id' => auth()->user()->uuid,
        ],
        )->assertSee($value);
    }

    /**
     * @deprecated
     */
    public function testGetAllCustomField(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                getAllCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertSee($value);
    }

    public function testGetAllCustomFieldQuery(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            query customFields( 
            $name : String!, 
            $system_module_uuid: String! , 
            $entity_id : String!) {
                customFields(
                    name: $name, 
                    system_module_uuid: $system_module_uuid,
                    entity_id : $entity_id
                )
            }',
            [
            'name' => $key,
            'system_module_uuid' => get_class(auth()->user()),
            'entity_id' => auth()->user()->uuid,
        ],
        )->assertSee($value);
    }

    public function testUserCustomFieldsResolveSystemModule(): void
    {
        $user = auth()->user();
        $key = 'system_module_probe_' . fake()->unique()->word();
        $user->set($key, ['hellos' => 1], isPublic: true);
        $systemModule = SystemModulesRepository::getByModelName(Users::class, app(Apps::class));

        $response = $this->graphQL( /** @lang GraphQL */
            '
            query {
                me {
                    custom_fields(first: 100) {
                        data {
                            name
                            systemModule {
                                uuid
                            }
                        }
                    }
                }
            }',
        );

        // Redis and the ecosystem connection don't roll back, so remove the probe before asserting.
        $user->del($key);

        $response
            ->assertSuccessful()
            ->assertJsonMissingPath('errors')
            ->assertJsonFragment([
                'name' => $key,
                'systemModule' => [
                    'uuid' => $systemModule->uuid,
                ],
            ]);
    }

    public function testDeleteCustomField(): void
    {
        $key = fake()->word;
        $userUuid = auth()->user()->uuid;

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => fake()->numberBetween(1, 100),
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => $userUuid,
            ],
        ],
        );

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                deleteCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => $userUuid,
            ],
        ],
        )->assertJson([
            'data' => [
                'deleteCustomField' => true,
            ],
        ]);
    }

    /** Both storage types matter: the Redis hash hands an int back as an int, not as JSON text. */
    public function testLargeIntegerCustomFieldsAreServedAsStrings(): void
    {
        $lead = Lead::factory()->create(['companies_id' => auth()->user()->getCurrentCompany()->getId()]);
        $lead->set('ZOHO_LEAD_ID', 2896936000576711333);
        $lead->set('ZOHO_DEAL_ID', '2896936000576711444');

        $body = $this->graphQL('
            query ($id: Mixed!) {
                leads(where: { column: ID, operator: EQ, value: $id }, first: 1) {
                    data { custom_fields(first: 100) { data { name value } } }
                }
            }
        ', ['id' => $lead->getId()])->assertSuccessful()->getContent();

        $this->assertStringContainsString('{"name":"ZOHO_LEAD_ID","value":"2896936000576711333"}', $body);
        $this->assertStringContainsString('{"name":"ZOHO_DEAL_ID","value":"2896936000576711444"}', $body);
    }
}
