<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Testing\TestResponse;
use Kanvas\Companies\Models\Companies;
use Tests\TestCase;
use Tests\Traits\MakesTemplates;

class TemplatesTest extends TestCase
{
    use MakesTemplates;

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
            mutation createTemplate($input: TemplateInput!) {
                createTemplate(
            input: $input
        ){
            name
            template
            is_system
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
                'is_system' => false,
                'template_variables' => [
                [
                    'key' => $contentName,
                    'value' => $contentValue,
                ],
                ],
                'template' => $template,
            ],
        ]
        )->assertJson([
            'data' => [
                'createTemplate' => [
                    'name' => $name,
                    'template' => $template,
                    'is_system' => false,
                    'template_variables' => [
                        [
                            'name' => $contentName,
                            'value' => $contentValue,
                        ],
                    ],
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
            mutation createTemplate($input: TemplateInput!) {
                createTemplate(
            input: $input
        ){
            name
            id
            template
            is_system
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
                'is_system' => false,
                'template_variables' => [
                [
                    'key' => $contentName,
                    'value' => $contentValue,
                ],
                ],
                'template' => $template,
            ],
        ]
        )->assertJson([
            'data' => [
                'createTemplate' => [
                    'name' => $name,
                    'template' => $template,
                    'is_system' => false,
                    'template_variables' => [
                        [
                            'name' => $contentName,
                            'value' => $contentValue,
                        ],
                    ],
                ],
            ],
        ]);

        $id = $response->json('data.createTemplate.id');

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation updateTemplate($id: ID!, $input: TemplateInput!) {
                updateTemplate(
            id: $id
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
            'id' => $id,
            'input' => [
                'name' => $name,
                'parent_template_id' => 0,
                'template_variables' => [
                [
                    'key' => $contentName,
                    'value' => $contentValue,
                ],
                ],
                'template' => $template,
            ],
        ]
        )->assertJson([
            'data' => [
                'updateTemplate' => [
                    'name' => $name,
                    'template' => $template,
                    'template_variables' => [
                        [
                            'name' => $contentName,
                            'value' => $contentValue,
                        ],
                    ],
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

    public function testDeleteTemplate(): void
    {
        $name = fake()->name;
        $contentName = 'content';
        $contentValue = 'This is an example content';
        $template = "<p>{$contentName}/p>";

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation createTemplate($input: TemplateInput!) {
                createTemplate(
            input: $input
        ){
            id
        }
        }
        ',
            [
                'input' => [
                    'name' => $name,
                    'parent_template_id' => 0,
                    'is_system' => false,
                    'template_variables' => [
                        [
                            'key' => $contentName,
                            'value' => $contentValue,
                        ],
                    ],
                    'template' => $template,
                ],
            ]
        );

        $id = $response->json('data.createTemplate.id');
        $this->graphQL(/** @lang GraphQL */ '
            mutation deleteTemplate($id: ID!) {
                deleteTemplate(
                    id: $id
                )
            }',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteTemplate' => true,
            ],
        ]);
    }

    public function testUpdateRefusesATemplateManagedByThePlatform(): void
    {
        $template = $this->makeHandMadeBladeTemplate('<p>hand made</p>', auth()->user()->getCurrentCompany());

        $this->updateTemplate($template->getId(), $template->name, '<p>replaced</p>')
            ->assertGraphQLErrorMessage("Template {$template->name} is managed by the platform and cannot be changed here.");

        $this->assertSame('<p>hand made</p>', $template->refresh()->template);
    }

    public function testCreateOverATemplateManagedByThePlatformIsRefused(): void
    {
        $template = $this->makeHandMadeBladeTemplate('<p>hand made</p>', auth()->user()->getCurrentCompany());

        $this->graphQL(/** @lang GraphQL */ '
            mutation createTemplate($input: TemplateInput!) {
                createTemplate(input: $input) { id }
            }',
            [
                'input' => [
                    'name' => $template->name,
                    'template' => '<p>replaced</p>',
                    'parent_template_id' => 0,
                    'template_variables' => [],
                ],
            ]
        )->assertGraphQLErrorMessage("Template {$template->name} is managed by the platform and cannot be changed here.");

        $this->assertSame('<p>hand made</p>', $template->refresh()->template);
    }

    public function testUpdateCannotReachAnotherCompanysTemplate(): void
    {
        $foreign = $this->makeTemplate('<p>theirs</p>', Companies::factory()->create());

        $response = $this->updateTemplate($foreign->getId(), $foreign->name, '<p>mine</p>');

        $this->assertStringContainsString('record found', (string) $response->json('errors.0.message'));
        $this->assertSame('<p>theirs</p>', $foreign->refresh()->template);
    }

    private function updateTemplate(int $id, string $name, string $body): TestResponse
    {
        return $this->graphQL(/** @lang GraphQL */ '
            mutation updateTemplate($id: ID!, $input: TemplateInput!) {
                updateTemplate(id: $id, input: $input) { id template }
            }',
            [
                'id' => $id,
                'input' => [
                    'name' => $name,
                    'template' => $body,
                    'parent_template_id' => 0,
                    'template_variables' => [],
                ],
            ]
        );
    }
}
