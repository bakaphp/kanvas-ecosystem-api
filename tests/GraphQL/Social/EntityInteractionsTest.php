<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Leads;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class EntityInteractionsTest extends TestCase
{
    /**
     * testSave.
     *
     * @return void
     */
    public function testLikeEntity(): void
    {
        $data = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Leads::class,
            'interacted_entity_id' => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                likeEntity(input: $input)
            }', [
                'input' => $data,
            ], [], [
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->uuid,
            ])->assertJson([
            'data' => ['likeEntity' => true],
        ]);
    }

    /**
     * testSave.
     *
     * @return void
     */
    public function testUnLikeEntity(): void
    {
        $data = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Leads::class,
            'interacted_entity_id' => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                unLikeEntity(input: $input)
            }', [
                'input' => $data,
            ], [], [
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->uuid,
            ])->assertJson([
            'data' => ['unLikeEntity' => true],
        ]);
    }

    /**
     * testSave.
     *
     * @return void
     */
    public function testDisLikeEntity(): void
    {
        $data = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Leads::class,
            'interacted_entity_id' => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                disLikeEntity(input: $input)
            }', [
                'input' => $data,
            ], [], [
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->uuid,
            ])->assertJson([
            'data' => ['disLikeEntity' => true],
        ]);
    }

    /**
     * testSave.
     *
     * @return void
     */
    public function testGetInteractionByEntity(): void
    {
        $data = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Leads::class,
            'interacted_entity_id' => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                getInteractionByEntity(input: $input){
                    like
                    dislike
                    save
                }
            }', [
                'input' => $data,
            ], [], [
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->uuid,
            ])->assertJson([
            'data' => [
                'getInteractionByEntity' => [
                    'like' => null,
                    'dislike' => null,
                    'save' => null,
                    ],
                ],
        ]);
    }

    /**
     * testSave.
     *
     * @return void
     */
    public function testGetAllEntityInteractions(): void
    {
        $uuid = fake()->uuid();

        $data = [
            'entity_id' => $uuid,
            'entity_namespace' => 'Leads',
            'interacted_entity_id' => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                likeEntity(input: $input)
            }', [
                'input' => $data,
            ], [], [
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->uuid,
            ])->assertJson([
            'data' => ['likeEntity' => true],
        ]);

        $this->graphQL(
            '
        {
            entityInteractions(
                    entity_id: "' . $uuid . '",
                    entity_namespace: "Leads"
                    ) {
                    data {
                        entity_id,
                        entity_namespace,
                        interactions{
                          like,
                          save
                        }
                    }
                }
            }'
        )->assertJson([
            'data' => [
                'entityInteractions' => [
                    'data' => [
                        [
                            'entity_id' => $uuid,
                            'entity_namespace' => 'Leads',
                            'interactions' => [
                                'like' => true,
                                'save' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
