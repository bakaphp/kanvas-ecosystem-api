<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Actions\CreateNotificationTypeAction;
use Kanvas\Notifications\DataTransferObject\NotificationType;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationChannel;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
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
        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification',
                'template' => '<html><body>Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values</body></html>',
                ])
        );
        $template = $createParentTemplate->execute();

        $notificationType = (new CreateNotificationTypeAction(
            new NotificationType(
                app(Apps::class),
                $user,
                'test-notification',
                'test notification',
                $template
            )
        ))->execute();

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::MAIL->value),
            $template
        );

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $metadata: NotificationMessageMetaDataInput!,
                    $message: Mixed!,
                ){
                    sendNotificationByMessage(
                        metadata: $metadata,
                        message: $message,
                    ){
                        sent,
                        message
                    }
                }
            ', [
                'metadata' => [
                    'notification_type_id' => $notificationType->getId(),
                    'distribution' => [
                        'type' => 'USERS',
                        'users_id' => [$user->getId()],
                    ],
                ],
                'message' => [
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]);

        $this->assertArrayHasKey('data', $response);
        $response->assertSee('sent');
        $response->assertSee('true');
        $response->assertSee('message');
    }

    /**
     * test_message_notification.
     *
     * @return void
     */
    public function testMessageNotificationToAllFollowers()
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification',
                'template' => '<html><body>Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values</body></html>',
                ])
        );
        $template = $createParentTemplate->execute();

        $notificationType = (new CreateNotificationTypeAction(
            new NotificationType(
                app(Apps::class),
                $user,
                'test-notification',
                'test notification',
                $template
            )
        ))->execute();

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::MAIL->value),
            $template
        );

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $metadata: NotificationMessageMetaDataInput!,
                    $message: Mixed!,
                ){
                    sendNotificationByMessage(
                        metadata: $metadata,
                        message: $message,
                    ){
                        sent,
                        message
                    }
                }
            ',
            [
                'metadata' => [
                    'notification_type_id' => $notificationType->getId(),
                    'distribution' => [
                        'type' => 'FOLLOWERS',
                        'users_id' => [],
                    ],
                ],
                'message' => [
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $this->assertArrayHasKey('data', $response);
        $response->assertSee('sent');
        $response->assertSee('true');
        $response->assertSee('message');
    }
}
