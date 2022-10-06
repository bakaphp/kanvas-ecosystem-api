<?php

declare(strict_types=1);
namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    public function test_notification()
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
                'password_confirmation' => 'password'
            ],
        ])->decodeResponseJson();
        $token = $response['data']['register']['token']['token'];
        $userData = Auth::user();
        $response = $this->graphQL(/** @lang GraphQL */ '
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
}
