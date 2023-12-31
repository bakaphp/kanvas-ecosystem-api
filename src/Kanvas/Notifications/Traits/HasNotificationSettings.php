<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationSettingsRepository;

trait HasNotificationSettings
{
    abstract public function getId(): mixed;

    /**
     * Check if the user has the notification setting enable.
     */
    public function isNotificationSettingEnable(
        NotificationTypes $type,
        AppInterface $app,
        int $channel = NotificationChannelEnum::MAIL->value
    ): bool {
        $userNotificationSetting = NotificationSettingsRepository::getNotificationSettingsByType(
            $this,
            $app,
            $type,
        );

        if ($userNotificationSetting) {
            return $userNotificationSetting->isEnable() ? $userNotificationSetting->hasChannel($channel) : false;
        }

        return true;
    }
}
