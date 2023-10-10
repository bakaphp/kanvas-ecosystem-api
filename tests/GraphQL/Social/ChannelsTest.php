<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ChannelsTest extends TestCase
{
    public function testCreateChannel()
    {

        $systemModule = SystemModules::all()->random(1)->first();
        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    name,
                    description,
                    entity_id,
                    entity_namespace
                }
            }
        ', [
            'input' => $data,
        ])->assertJson([
            'data' => [
                'createSocialChannel' => [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'entity_id' => $data['entity_id'],
                    'entity_namespace' => $data['entity_namespace_uuid'],
                ],
            ],
        ]);
    }

    public function testUpdateChannel()
    {

        $systemModule = SystemModules::all()->random(1)->first();
        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    id
                }
            }
        ', [
            'input' => $data,
        ]);

        $data['name'] = fake()->name();
        $this->graphQL(
            '
            mutation updateSocialChannel(
                $id: ID!
                $input: SocialChannelInput!
            ){
                updateSocialChannel(id: $id, input: $input)
                {
                    name
                }
            }
        ',
            [
                'id' => $response['data']['createSocialChannel']['id'],
                'input' => $data,
            ]
        )->assertJson([
            'data' => [
                'updateSocialChannel' => [
                    'name' => $data['name'],
                ],
            ],
        ]);
    }

    public function testAttachUserToSocialChannel()
    {
        $systemModule = SystemModules::all()->random(1)->first();
        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    id
                }
            }
        ', [
            'input' => $data,
        ]);
        $channelId = $response['data']['createSocialChannel']['id'];

        $user = Users::all()->random(1)->first();

        $response = $this->graphQL(
            '
            mutation attachUserToSocialChannel(
                $channel_id:ID!
                $user_id: ID!
                $roles_id: UsersRolesChannel!
                ) {
                    attachUserToSocialChannel(
                        channel_id: $channel_id,
                        user_id: $user_id
                        roles_id: $roles_id
                    ) {
                        users {
                            id 
                        }
                    }
            }
        ',
            [
                'channel_id' => $channelId,
                'user_id' => $user->id,
                'roles_id' => 'Admin',
            ]
        );
        dump($response);
        $response->assertJsonStructure([
            'data' => [
                'attachUserToSocialChannel' => [
                    'users' => [
                        0 => ['id'],
                    ],
                ],
            ],
        ]);
    }
}
