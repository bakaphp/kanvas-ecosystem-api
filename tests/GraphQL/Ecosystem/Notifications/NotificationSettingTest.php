<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Kanvas\Notifications\Models\NotificationTypes;
use Tests\TestCase;

class NotificationSettingTest extends TestCase
{
    /**
     * testListAllSetting.
     */
    public function testListAllSetting(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            {
                notificationSettings(
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
    */
    public function testMuteAll(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                notificationsMuteAll
            }
            ');
        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'notificationsMuteAll' => 'All Notifications are muted',
            ],
        ]);
    }

    /**
     * testSetNotificationSettings.
     */
    public function testSetNotificationSettings(): void
    {
        $notificationType = NotificationTypes::first();
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation{
                setNotificationSettings(
                     
                        notifications_types_id: ' . $notificationType->id . ',
                        is_enabled: 1,
                        channels: [MAIL]
                    
                ){
                    users_id
                }
            }
            ');

        $this->assertArrayHasKey('data', $response);
    }
}
