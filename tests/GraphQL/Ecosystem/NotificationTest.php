<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class NotificationTest extends TestCase
{
    /**
     * test_notification.
     *
     * @return void
     */
    public function testNotification()
    {
        $email = fake()->email;

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                  user{
                    email
                  }
                  token{
                      token
                      refresh_token
                      token_expires
                      refresh_token_expires
                      time
                      timezone
                  }
                }
              }
        ', [
            'data' => [
                'email' => fake()->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ],
        ])->decodeResponseJson();
        $token = $response['data']['register']['token']['token'];
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->graphQL(/** @lang GraphQL */ '
            {
                notifications(first: 10) {
                       data {
                            id,
                            types {
                                id
                            }
                        },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                }
            }
            ');
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * test_readAll.
     *
     * @return void
     */
    public function testReadAll()
    {
        $email = fake()->email;

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                  user{
                    email,
                    id
                  }
                  token{
                      token
                      refresh_token
                      token_expires
                      refresh_token_expires
                      time
                      timezone
                  }
                }
              }
        ', [
            'data' => [
                'email' => fake()->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ],
        ])->decodeResponseJson();
        $token = $response['data']['register']['token']['token'];
        $userId = $response['data']['register']['user']['id'];
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->graphQL(/** @lang GraphQL */ '
            mutation {
                readAllNotifications
            }
            ');
        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'readAllNotifications' => true,
            ],
        ]);
    }
}
