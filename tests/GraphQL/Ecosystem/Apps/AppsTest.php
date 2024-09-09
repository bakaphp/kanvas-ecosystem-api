<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Tests\TestCase;

class AppsTest extends TestCase
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
                    'adminAppSetting'
                ]
            ]);
    }
}
