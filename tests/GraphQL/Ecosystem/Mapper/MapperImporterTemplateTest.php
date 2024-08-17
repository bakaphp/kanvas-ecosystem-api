<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Mapper;

use Tests\TestCase;

class MapperImporterTemplateTest extends TestCase
{
    /**
     * test_save.
     */
    public function testCreate(): void
    {
        $mapperImporterTemplate = [
          'name' => fake()->name,
          'description' => fake()->sentence,
          'attributes' => [
              [
                  'name' => fake()->name,
                  'value' => fake()->sentence,
                  'children' => [
                      [
                          'name' => fake()->name,
                          'value' => fake()->sentence,
                      ],
                  ],
              ],
          ],
        ];
        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: ImporterTemplateInput!
                ){
                    createImporterTemplate(input: $input) {
                        name,
                        description,
                        attributes {
                            name,
                            value,
                        }
                    }
                }
            ',
            [
                'input' => $mapperImporterTemplate,
            ],
        );
        $response->assertJson([
            'data' => [
                'createImporterTemplate' => [
                    'name' => $mapperImporterTemplate['name'],
                    'description' => $mapperImporterTemplate['description'],
                    'attributes' => [
                        [
                            'name' => $mapperImporterTemplate['attributes'][0]['name'],
                            'value' => $mapperImporterTemplate['attributes'][0]['value'],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertJsonStructure([
            'data' => [
                'createImporterTemplate' => [
                    'name',
                    'attributes' => [
                        [
                            'name',
                            'value',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
