<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class AppTemplateTest extends TestCase
{
    public function testAddTemplateToApp(): void
    {
        $app = app(Apps::class);

        $input = [
            'name' => 'tone_clean_app_' . fake()->unique()->word(),
            'template' => '<!DOCTYPE html><html><body>{!! $message !!}</body></html>',
        ];

        $this->graphQL(/** @lang GraphQL */ '
            mutation($id: String!, $input: appTemplateInput!) {
                addTemplateToApp(id: $id, input: $input) {
                    id
                    name
                    template
                }
            }',
            [
                'id' => $app->key,
                'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'addTemplateToApp' => [
                    'name' => $input['name'],
                    'template' => $input['template'],
                ],
            ],
        ]);
    }
}
