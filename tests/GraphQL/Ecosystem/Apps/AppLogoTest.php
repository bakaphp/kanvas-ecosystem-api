<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class AppLogoTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

    public function testUpdateAppLogo(): void
    {
        $app = app(Apps::class);

        $operations = [
            'query' => '
                mutation updateAppLogo($id: ID!, $file: Upload!) {
                    updateAppLogo(id: $id, file: $file) {
                        id
                        name
                        logo {
                            name
                            url
                        }
                    }
                }
            ',
            'variables' => [
                'id' => $app->key,
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
        ];

        $this->multipartGraphQL($operations, $map, $file, $this->getAppKeyHeader())
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'updateAppLogo' => [
                        'id' => (string) $app->id,
                        'logo' => [
                            'name' => 'logo.png',
                        ],
                    ],
                ],
            ]);
    }

    public function testGetAppWithLogo(): void
    {
        $this->graphQL('
            query {
                apps(first: 1) {
                    data {
                        id
                        name
                        logo {
                            name
                            url
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'apps' => [
                    'data' => [
                        ['id', 'name'],
                    ],
                ],
            ],
        ]);
    }
}
