<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Lead\Models\Lead;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class EntityInteractionsTest extends TestCase
{
    /**
     * testSave.
     */
    public function testLikeEntity(): void
    {
        $data = [
            'entity_id'                   => fake()->uuid(),
            'entity_namespace'            => Lead::class,
            'interacted_entity_id'        => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                likeEntity(input: $input)
            }', [
            'input' => $data,
        ], [], [
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->first()->uuid,
        ])->assertJson([
            'data' => ['likeEntity' => true],
        ]);
    }

    /**
     * testSave.
     */
    public function testUnLikeEntity(): void
    {
        $data = [
            'entity_id'                   => fake()->uuid(),
            'entity_namespace'            => Lead::class,
            'interacted_entity_id'        => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                unLikeEntity(input: $input)
            }', [
            'input' => $data,
        ], [], [
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->first()->uuid,
        ])->assertJson([
            'data' => ['unLikeEntity' => true],
        ]);
    }

    /**
     * testSave.
     */
    public function testDisLikeEntity(): void
    {
        $data = [
            'entity_id'                   => fake()->uuid(),
            'entity_namespace'            => Lead::class,
            'interacted_entity_id'        => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                disLikeEntity(input: $input)
            }', [
            'input' => $data,
        ], [], [
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->first()->uuid,
        ])->assertJson([
            'data' => ['disLikeEntity' => true],
        ]);
    }

    /**
     * testSave.
     */
    public function testGetInteractionByEntity(): void
    {
        $data = [
            'entity_id'                   => fake()->uuid(),
            'entity_namespace'            => Lead::class,
            'interacted_entity_id'        => fake()->uuid(),
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
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->first()->uuid,
        ])->assertJson([
            'data' => [
                'getInteractionByEntity' => [
                    'like'    => null,
                    'dislike' => null,
                    'save'    => null,
                ],
            ],
        ]);
    }

    /**
     * testSave.
     */
    public function testGetAllEntityInteractions(): void
    {
        $uuid = fake()->uuid();

        $data = [
            'entity_id'                   => $uuid,
            'entity_namespace'            => 'Lead',
            'interacted_entity_id'        => fake()->uuid(),
            'interacted_entity_namespace' => Variants::class,
        ];

        $response = $this->graphQL('
            mutation($input: LikeEntityInput!){
                likeEntity(input: $input)
            }', [
            'input' => $data,
        ], [], [
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => auth()->user()->getCurrentCompany()->defaultBranch()->first()->uuid,
        ])->assertJson([
            'data' => ['likeEntity' => true],
        ]);

        $this->graphQL(
            '
        {
            entityInteractions(
                    entity_id: "'.$uuid.'",
                    entity_namespace: "Lead"
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
                            'entity_id'        => $uuid,
                            'entity_namespace' => 'Lead',
                            'interactions'     => [
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
