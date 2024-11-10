<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Internal;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Actions\AppUsersNotificationByRoleAction;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    public function testAppUserNotificationByRoleActivity()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $params = [
            'template_name' => EmailTemplateEnum::USER_INVITE->value,
            'from_user_id' => $user->getId(),
            'role' => RolesEnums::OWNER->value,
        ];

        $action = new AppUsersNotificationByRoleAction($app, $user, $params);
        $result = $action->execute();

        $this->assertArrayHasKey('totalNotificationSent', $result);
        $this->assertEquals(1, $result['totalNotificationSent']);
    }
}
