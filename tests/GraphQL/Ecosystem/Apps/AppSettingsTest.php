<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    public function testAppSettings()
    {
        $this->graphQL( /** @lang GraphQL */
            '
            {
                appSetting {     
                    name,
                    description,
                    settings
                }
            }
            
            
            '
        )
        ->assertSuccessful()
        ->assertSee('name')
        ->assertSee('description')
        ->assertSee('settings');
    }

    public function testAppSettingVisibility()
    {
        $app = app(Apps::class);
        $app->set('public_test', 'public_test', true);
        $app->set('private_test', 'private_test', false);

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                appSetting {     
                    name,
                    description,
                    settings
                }
            }
            
            
            '
        )
        ->json();

        $this->assertArrayHasKey('public_test', $response['data']['appSetting']['settings']);
        $this->assertArrayNotHasKey('private_test', $response['data']['appSetting']['settings']);
    }

    public function testSetAppSetting()
    {
        $app = app(Apps::class);
        $input = [
            'key' => 'test',
            'value' => 'test',
            'entity_uuid' => $app->uuid,
        ];
        $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: ModuleConfigInput!
            ){
                setAppSetting(
                    input: $input
                ) 
            }',
            [
                'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'setAppSetting' => true,
            ],
        ]);
    }

    public function testDeleteAppSetting()
    {
        $app = app(Apps::class);
        $input = 'test';

        $app->set('test', 'test');

        $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $key: String!
            ){
                deleteAppSetting(
                    key: $key
                ) 
            }',
            [
                'key' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'deleteAppSetting' => true,
            ],
        ]);
    }

    public function testSetAppSettingPreservesNumericStringPrecision(): void
    {
        $app = app(Apps::class);
        $key = 'TEST_OAUTH_CLIENT_ID_PRECISION';
        $value = '474083765751.10750363865122';

        try {
            $this->graphQL('
                mutation($input: ModuleConfigInput!) {
                    setAppSetting(input: $input)
                }
            ', [
                'input' => [
                    'key' => $key,
                    'value' => $value,
                    'entity_uuid' => $app->uuid,
                    'public' => false,
                ],
            ], [], [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ])->assertJson(['data' => ['setAppSetting' => true]]);

            $this->assertSame($value, $app->get($key));
            $this->assertSame($value, $app->getAllSettings(fromRedis: false)[$key]);
        } finally {
            $app->del($key);
        }
    }

    public function testDeprecatedAdminAppSettings()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $this->graphQL( /** @lang GraphQL */
            '
            {
                appSetting {     
                    name,
                    description,
                    settings
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name')
        ->assertSee('description')
        ->assertSee('settings');
    }

    public function testAdminAppSettings()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $this->graphQL( /** @lang GraphQL */
            '
            {
                adminAppSettings {     
                    key,
                    value,
                    public
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
        ->assertSuccessful()
        ->assertSee('key')
        ->assertSee('value')
        ->assertSee('public');
    }

    public function testAdminAppSetting()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $allSettings = $app->getAll();
        $firstSetting = array_keys($allSettings)[0];

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                adminAppSetting(key: "' . $firstSetting . '") 
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'adminAppSetting',
                ],
            ]);
    }
}
