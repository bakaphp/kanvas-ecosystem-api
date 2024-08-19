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
                  'mapping_field' => fake()->sentence,
                  'children' => [
                      [
                          'name' => fake()->name,
                          'mapping_field' => fake()->sentence,
                      ],
                  ],
              ],
          ],
        ];
        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: ImporterTemplateInput!
                ){
                    createMapperImporterTemplate(input: $input) {
                        name,
                        description,
                        attributes {
                            name,
                            mapping_field ,
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
                            'mapping_field' => $mapperImporterTemplate['attributes'][0]['mapping_field'],
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
                            'mapping_field',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
