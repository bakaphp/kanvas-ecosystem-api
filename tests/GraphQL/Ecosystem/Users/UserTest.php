<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class UserTest extends TestCase
{
    protected static LoginInput $loginData;

    /**
     * Set login credentials.
     */
    public static function loginData(): LoginInput
    {
        if (empty(self::$loginData)) {
            self::$loginData = LoginInput::from([
                'email' => fake()->email,
                'password' => fake()->password(8),
                'ip' => request()->ip(),
            ]);
        }

        return self::$loginData;
    }

    public function testEditUserdata(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    displayname
                    description
                    sex,
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'timezone' => 'America/New_York',
                    'welcome' => true,
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('firstname', $firstname)
        ->assertSee('lastname', $lastname)
        ->assertSee('displayname', $displayname)
        ->assertSee('description')
        ->assertSee('custom_fields')
        ->assertSee('sex');
    }

    public function testEditAddress(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();
        $address = [
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zip' => fake()->postcode(),
            'is_default' => true,
            'country_id' => 1,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'timezone' => 'America/New_York',
                    'welcome' => true,
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                    'addresses' => [
                        $address,
                    ],
                ],
            ]
        );
        $response->assertJson([
            'data' => [
                'updateUser' => [
                    'addresses' => [
                        [
                            'address' => $address['address'],
                            'city' => $address['city'],
                            'state' => $address['state'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testEditDuplicateAddress(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();
        $address = [
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zip' => fake()->postcode(),
            'is_default' => true,
            'country_id' => 1,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    //'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'timezone' => 'America/New_York',
                    'welcome' => true,
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                    'addresses' => [
                        $address,
                    ],
                ],
            ]
        );
        $response->assertJsonFragment([
            'address' => $address['address'],
            'city' => $address['city'],
            'state' => $address['state'],
        ]);
        $addresses = collect($response->json('data.updateUser.addresses'));
        $addressId = $addresses->firstWhere('address', $address['address'])['id'];

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                        'id' => 0,
                        'data' => [
                            'addresses' => [
                                $address,
                            ],
                        ],
                    ]
        );
        $addressIdDuplicate = $response->json('data.updateUser.addresses.0.id');
        $this->assertEquals($addressId, $addressIdDuplicate);
    }

    public function testUpdateAddress(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();
        $address = [
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zip' => fake()->postcode(),
            'is_default' => true,
            'country_id' => 1,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    //'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'timezone' => 'America/New_York',
                    'welcome' => true,
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                    'addresses' => [
                        $address,
                    ],
                ],
            ]
        );
        $response->assertJsonFragment([
            'address' => $address['address'],
            'city' => $address['city'],
            'state' => $address['state'],
        ]);
        $addresses = collect($response->json('data.updateUser.addresses'));
        $addressId = $addresses->firstWhere('address', $address['address'])['id'];
        $address['id'] = $addressId;
        $address['address'] = fake()->address();
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                        'id' => 0,
                        'data' => [
                            'addresses' => [
                                $address,
                            ],
                        ],
                    ]
        );
        $response->assertJsonFragment([
            'address' => $address['address'],
            'city' => $address['city'],
            'state' => $address['state'],
        ]);
    }

    public function testDeleteUserAddress(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();
        $address = [
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zip' => fake()->postcode(),
            'is_default' => true,
            'country_id' => 1,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    addresses {
                        id
                        address
                        city
                        state
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
//                    'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'timezone' => 'America/New_York',
                    'welcome' => true,
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                    'addresses' => [
                        $address,
                    ],
                ],
            ]
        );
        $addressId = $response->json('data.updateUser.addresses.0.id');
        $this->graphQL(/** @lang GraphQL */
            '
            mutation deleteUserAddress($id: ID!) {
                deleteUserAddress(id: $id)
            }',
            [
                'id' => $addressId,
            ]
        )->assertJson([
            'data' => [
                'deleteUserAddress' => true,
            ],
        ]);
    }

    public function testChangePassword()
    {
        $freshUser = $this->createUser();
        $this->actingAs($freshUser, 'api');

        $newPassword = 'abc123456';
        $currentPassword = 'abcabc123456';
        $userData = $this->graphQL(/** @lang GraphQL */ '
            {
                me {
                    id,
                    uuid,
                    email
                }
            }
        ');

        $userDataProfile = $userData->json();
        $user = Users::getById($userDataProfile['data']['me']['id']);
        $user->resetPassword($currentPassword, app(Apps::class));

        $email = $userDataProfile['data']['me']['email'];

        $this->graphQL(/** @lang GraphQL */ '
            mutation changePassword(
                $current_password: String!
                $new_password: String!
                $new_password_confirmation: String
            ) {
                changePassword(
                    current_password: $current_password
                    new_password: $new_password
                    new_password_confirmation: $new_password_confirmation)
            }
        ', [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])->assertJson([
            'data' => [
                'changePassword' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation login($data: LoginInput!) {
                login(data: $data) {
                  id
                  token
                  refresh_token
                  token_expires
                  refresh_token_expires
                  time
                  timezone
                }
              }

        ', [
            'data' => [
                'email' => $email,
                'password' => $currentPassword,
            ],
        ])
        ->assertSuccessful()
        ->assertSee('errors')
        ->assertSee('message')
        ->assertSee('Invalid email or password.');

        $this->graphQL(/** @lang GraphQL */ '
            mutation login($data: LoginInput!) {
                login(data: $data) {
                  id
                  token
                  refresh_token
                  token_expires
                  refresh_token_expires
                  time
                  timezone
                }
              }

        ', [
            'data' => [
                'email' => $email,
                'password' => $newPassword,
            ],
        ])
        ->assertSuccessful()
        ->assertSee('id')
        ->assertSee('token')
        ->assertSee('token_expires')
        ->assertSee('refresh_token_expires')
        ->assertSee('time')
        ->assertSee('timezone')
        ->assertSee('refresh_token');
    }

    public function testDuplicateChangePassword()
    {
        $newPassword = 'abc12345676';
        $currentPassword = 'abc12345676';
        $userData = $this->graphQL(/** @lang GraphQL */ '
            { 
                me {
                    id,
                    uuid,
                    email
                }
            }
        ');

        $userDataProfile = $userData->json();
        $user = Users::getById($userDataProfile['data']['me']['id']);
        $user->resetPassword($currentPassword, app(Apps::class));

        $email = $userDataProfile['data']['me']['email'];

        $this->graphQL(/** @lang GraphQL */ '
            mutation changePassword(
                $current_password: String!
                $new_password: String!
                $new_password_confirmation: String
            ) {
                changePassword(
                    current_password: $current_password
                    new_password: $new_password
                    new_password_confirmation: $new_password_confirmation)
            }
        ', [
            'current_password' => $currentPassword,
            'new_password' => $currentPassword,
            'new_password_confirmation' => $currentPassword,
        ])
        ->assertSuccessful()
        ->assertSee('errors')
        ->assertSee('message');
    }

    public function testChangeEmail(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation updateEmail(
                $email: String!
            ) {
                updateEmail(
                    email: $email
                )
            }
        ', [
            'email' => fake()->email,
        ])->assertJson([
            'data' => [
                'updateEmail' => true,
            ],
        ]);
    }

    public function testChangeDisplayName(): void
    {
        Mail::fake();

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateDisplayname(
                $displayname: String!
            ) {
                updateDisplayname(
                    displayname: $displayname
                )
            }
        ', [
            'displayname' => fake()->userName(),
        ])->assertJson([
            'data' => [
                'updateDisplayname' => true,
            ],
        ]);
    }

    public function testGetUserByDisplayNameFromApp()
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $this->graphQL(/** @lang GraphQL */
            '
            query userByDisplayName($displayname: String!){
                userByDisplayName(displayname: $displayname){
                    id
                    email
                }
            }
        ',
            [
            'displayname' => $user->displayname,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'userByDisplayName' => [
                    'id' => $user->getId(),
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function testSetUserSetting()
    {
        $app = app(Apps::class);

        $input = [
            'key' => 'test',
            'value' => 'test',
            'entity_uuid' => Users::getById(1)->uuid,
        ];
        $this->graphQL(/** @lang GraphQL */
            '
            mutation setUserSetting($input: ModuleConfigInput!){
                setUserSetting(input: $input)
            }
        ',
            [
            'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'setUserSetting' => true,
            ],
        ]);
    }

    public function testDeleteUserSetting()
    {
        $app = app(Apps::class);

        $input = [
            'key' => 'test',
            'value' => 'test',
            'entity_uuid' => Users::getById(1)->uuid,
        ];
        $this->graphQL(/** @lang GraphQL */
            '
            mutation deleteUserSetting($input: ModuleConfigInput!){
                deleteUserSetting(input: $input)
            }
        ',
            [
            'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'deleteUserSetting' => true,
            ],
        ]);
    }

    public function testRequestDeleteAccount()
    {
        $this->graphQL(/** @lang GraphQL */
            '
            mutation requestDeleteAccount {
                requestDeleteAccount
            }
        '
        )->assertJson([
            'data' => [
                'requestDeleteAccount' => true,
            ],
        ]);
    }

    public function testUploadFileToUser()
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
            mutation uploadFileToUser($id: ID!, $file: Upload!) {
                uploadFileToUser(id: $id, file: $file)
                    { 
                        id
                        displayname
                        files{
                            data {
                                name
                                url
                            }
                        }
                    } 
                }
            ',
            'variables' => [
                'id' => 0,
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $this->multipartGraphQL($operations, $map, $file)->assertSee('id')
            ->assertSee('displayname')
            ->assertSee('files')
            ->assertSee('avatar.jpg');
    }

    public function testUpdatePhotoProfile(): void
    {
        $user = auth()->user();

        $operations = [
            'query' => '
                mutation updatePhotoProfile($file: Upload!, $user_id: ID!) {
                    updatePhotoProfile(file: $file, user_id: $user_id) {
                        id
                        displayname
                        photo {
                            name
                            url
                        }
                    }
                }
            ',
            'variables' => [
                'user_id' => $user->getId(),
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('user-photo.png', 100, 'image/png'),
        ];

        $this->multipartGraphQL($operations, $map, $file)
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'updatePhotoProfile' => [
                        'id' => (string) $user->getId(),
                    ],
                ],
            ]);
    }

    public function testSaveUserAppPreferences()
    {
        $this->graphQL(/** @lang GraphQL */
            '
            mutation saveUserAppPreferences(
                $preferences: Mixed!
            ) {
            saveUserAppPreferences(
            preferences: $preferences
            )
        }
        ',
            [
                'preferences' => [
                    'preference_1' => 1,
                    'preference_2' => 1,
                    'preference_3' => 0,
                ],
            ]
        )->assertJson([
            'data' => [
                'saveUserAppPreferences' => true,
            ],
        ]);
    }

    public function testBlockedCustomFieldsAllowsNonBlockedFields(): void
    {
        $app = app(Apps::class);
        $app->set('blocked_user_custom_fields', ['blocked_field', 'another_blocked_field']);

        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'custom_fields' => [
                        [
                            'name' => 'allowed_field',
                            'data' => 'test_value',
                        ],
                    ],
                ],
            ]
        );

        $response->assertSuccessful()
            ->assertSee('allowed_field')
            ->assertSee('test_value');

        $user = auth()->user();
        $this->assertEquals('test_value', $user->get('allowed_field'));

        $app->del('blocked_user_custom_fields');
    }

    public function testBlockedCustomFieldsBlocksConfiguredFields(): void
    {
        $app = app(Apps::class);
        $app->set('blocked_user_custom_fields', ['blocked_field', 'another_blocked_field']);

        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'custom_fields' => [
                        [
                            'name' => 'blocked_field',
                            'data' => 'should_not_be_set',
                        ],
                    ],
                ],
            ]
        );

        $response->assertSuccessful();

        $user = auth()->user();
        $this->assertNull($user->get('blocked_field'));

        $app->del('blocked_user_custom_fields');
    }

    public function testBlockedCustomFieldsFiltersPartiallyBlockedFields(): void
    {
        $app = app(Apps::class);
        $app->set('blocked_user_custom_fields', ['blocked_field']);

        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'custom_fields' => [
                        [
                            'name' => 'allowed_field',
                            'data' => 'allowed_value',
                        ],
                        [
                            'name' => 'blocked_field',
                            'data' => 'should_not_be_set',
                        ],
                    ],
                ],
            ]
        );

        $response->assertSuccessful()
            ->assertSee('allowed_field')
            ->assertSee('allowed_value');

        $user = auth()->user();
        $this->assertEquals('allowed_value', $user->get('allowed_field'));
        $this->assertNull($user->get('blocked_field'));

        $app->del('blocked_user_custom_fields');
    }

    public function testBlockedCustomFieldsAllowsAllWhenNotConfigured(): void
    {
        $app = app(Apps::class);
        $app->del('blocked_user_custom_fields');

        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'custom_fields' => [
                        [
                            'name' => 'any_field',
                            'data' => 'any_value',
                        ],
                    ],
                ],
            ]
        );

        $response->assertSuccessful()
            ->assertSee('any_field')
            ->assertSee('any_value');

        $user = auth()->user();
        $this->assertEquals('any_value', $user->get('any_field'));
    }
}
