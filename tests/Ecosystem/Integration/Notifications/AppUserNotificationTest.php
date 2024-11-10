<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Actions\AppUsersNotificationByRoleAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Tests\TestCase;

final class AppUserNotificationTest extends TestCase
{
    public function testAppUserNotificationByRoleActivity()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $params = [
            'template_name' => EmailTemplateEnum::USER_INVITE->value,
            'from_user_id' => $user->getId(),
            'role' => RolesEnums::USER->value,
        ];

        $action = new AppUsersNotificationByRoleAction($app, $user, $params);
        $result = $action->execute();

        $this->assertArrayHasKey('totalNotificationSent', $result);
        $this->assertGreaterThanOrEqual(0, $result['totalNotificationSent']);
    }
}
