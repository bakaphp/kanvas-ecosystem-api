<?php
declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Notifications\Models\NotificationTypes;
use Tests\TestCase;

class NotificationSettingTest extends TestCase
{
    /**
     * testListAllSetting.
     *
     * @return void
     */
    public function testListAllSetting() : void
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
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->graphQL(/** @lang GraphQL */ '
            {
                notificationSettingsListAll(
                    first: 10
                ) {
                    data {
                        users_id
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
     * testMuteAll.
     *
     * @return void
     */
    public function testMuteAll() : void
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
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->graphQL(/** @lang GraphQL */ '
            mutation {
                notificationsMuteAll
            }
            ');
        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'notificationsMuteAll' => 'All Notifications are muted'
            ],
        ]);
    }

    /**
     * testSetNotificationSettings.
     *
     * @return void
     */
    public function testSetNotificationSettings() : void
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
                'password_confirmation' => 'password'
            ],
        ])->decodeResponseJson();
        $token = $response['data']['register']['token']['token'];
        $userId = $response['data']['register']['user']['id'];
        $notificationType = NotificationTypes::first();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->graphQL(/** @lang GraphQL */ '
            mutation{
                setNotificationSettings(
                     
                        notifications_types_id: ' . $notificationType->id . ',
                        is_enabled: 1,
                        channels: ""
                    
                ){
                    users_id
                }
            }
            ');
        $this->assertArrayHasKey('data', $response);
    }
}
