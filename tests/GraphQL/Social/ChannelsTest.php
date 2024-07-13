<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

class ChannelsTest extends TestCase
{
    public function testCreateChannel()
    {
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
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
                    'entity_namespace' => $systemModule->model_name
                ],
            ],
        ]);
    }

    public function testUpdateChannel()
    {
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
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
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
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

        $response = $this->graphQL(
            '
            mutation inviteUser($input: InviteInput!) {
                inviteUser(input: $input) {
                    id,
                    invite_hash
                }
            }
        ',
            [
                'input' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->name(),
                    'lastname' => fake()->name(),
                    'custom_fields' => [],
                ],
            ]
        );
        $inviteHash = $response['data']['inviteUser']['invite_hash'];

        $response = $this->graphQL(
            '
                mutation processInvite(
                    $input: CompleteInviteInput!
                ){
                    processInvite(input: $input){
                        id
                    }
                }
            ',
            [
                'input' => [
                    'invite_hash' => $inviteHash,
                    'lastname' => fake()->name(),
                    'firstname' => fake()->name(),
                    'password' => fake()->password(8),
                ],
            ]
        );
        $user = $response['data']['processInvite']['id'];
        $response = $this->graphQL(
            '
            mutation attachUserToSocialChannel(
                $input: AttachUserInput!
                ) {
                    attachUserToSocialChannel(
                        input: $input

                    ) {
                        users {
                            id 
                        }
                    }
            }
        ',
            [
                'input' => [
                    'channel_id' => $channelId,
                    'user_id' => $user,
                    'roles_id' => 'Admin',
                ],
            ]
        );

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
