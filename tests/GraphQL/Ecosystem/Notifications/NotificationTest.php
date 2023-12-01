<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
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

    /**
     * test_readAll.
     *
     * @return void
     */
    public function testReadAll()
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
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

    /**
     * test_message_notification.
     *
     * @return void
     */
    public function testMessageNotificationToOneFollower()
    {
        $user = auth()->user();
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $message: Mixed!,
                
                ){
                    sendNotificationByMessage(
                        message: $message,
                    )
                }
            ', [
                'metadata' => [
                    'verb' => 'entity',
                    'event' => 'creation',
                    'channels' => ['push','email'],
                    'distribution' => [
                        'type' => 'one',
                        'userId' => $user->getId(),
                    ],
                ],
                'message' => [
                    
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ]);

        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'sendNotificationByMessage' => true,
            ],
        ]);
    }

    /**
     * test_message_notification.
     *
     * @return void
     */
    public function testMessageNotificationToAllFollowers()
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $message: Mixed!,
                
                ){
                    sendNotificationByMessage(
                        message: $message,
                    )
                }
            ', [
                'metadata' => [
                    'verb' => 'entity',
                    'event' => 'creation',
                    'channels' => ['push','email'],
                    'distribution' => [
                        'type' => 'all',
                    ],
                ],
                'message' => [
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ]);

        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'sendNotificationByMessage' => true,
            ],
        ]);
    }
}
