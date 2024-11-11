<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\CreateNotificationTypeAction;
use Kanvas\Notifications\DataTransferObject\NotificationType;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationChannel;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\TestCase;

final class DynamicNotificationTest extends TestCase
{
    public function testDynamicNotificationTypeNotification()
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

        $createPushTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification-push',
                'template' => 'Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values',
                ])
        );
        $pushTemplate = $createPushTemplate->execute();

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

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::PUSH->value),
            $pushTemplate
        );

        $dynamicNotification = new DynamicKanvasNotification(
            $notificationType,
            ['dynamic' => 'dynamic ' . time()]
        );

        Notification::fake();

        $user->notify($dynamicNotification);

        Notification::assertSentTo(
            $user,
            DynamicKanvasNotification::class
        );
    }
}
