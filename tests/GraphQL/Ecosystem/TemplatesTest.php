<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Tests\TestCase;

class TemplatesTest extends TestCase
{
    /**
     * Test Create Template
     */
    public function testCreateTemplate(): void
    {
        $name = fake()->name;
        $contentName = 'content';
        $contentValue = 'This is an example content';
        $template = "<p>{$contentName}/p>";

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation createTemplate($input: TemplatesInput!) {
                createTemplate(
            input: $input
        ){
            name
            template
            template_variables {
            name
            value
        }
            }
        }
        ',
            [
            'input' => [
                'name' => $name,
                'parent_template_id' => 0,
                'template_variables' => [
                [
                    'key' => $contentName,
                    'value' => $contentValue
                ]
                ],
                'template' => $template
            ]
        ]
        )->assertJson([
            'data' => [
                'createTemplate' => [

                    'name' => $name,
                    'template' => $template,
                    'template_variables' => [
                        [
                            'name' => $contentName,
                            'value' => $contentValue
                        ]
                    ]
                ],
            ],
        ]);
    }

    /**
     * Test Update Template
     */
    public function testUpdateTemplate(): void
    {
        $name = fake()->name;
        $contentName = 'content';
        $contentValue = 'This is an example content';
        $template = "<p>{$contentName}/p>";

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation updateTemplate($input: TemplatesInput!) {
                updateTemplate(
            input: $input
        ){
            name
            template
            template_variables {
            name
            value
        }
            }
        }
        ',
            [
            'input' => [
                'name' => $name,
                'parent_template_id' => 0,
                'template_variables' => [
                [
                    'key' => $contentName,
                    'value' => $contentValue
                ]
                ],
                'template' => $template
            ]
        ]
        )->assertJson([
            'data' => [
                'updateTemplate' => [

                    'name' => $name,
                    'template' => $template,
                    'template_variables' => [
                        [
                            'name' => $contentName,
                            'value' => $contentValue
                        ]
                    ]
                ],
            ],
        ]);
    }

    /**
     * Test Get Template
     */
    public function testGetTemplate(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
            templates(first: 20) {
                data {
                template
                name
                template_variables {
                    name
                    value
                }
              }
            }
        }
        '
        );

        $this->assertArrayHasKey('data', $response);
    }
}