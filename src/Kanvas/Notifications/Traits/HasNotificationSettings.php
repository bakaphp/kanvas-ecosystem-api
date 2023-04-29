<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

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
        string $channel = NotificationChannelEnum::MAIL
    ): bool {
        $userNotificationSetting = NotificationSettingsRepository::getNotificationSettingsByType(
            $this,
            app(Apps::class),
            $type,
        );

        if ($userNotificationSetting && $userNotificationSetting->isEnable()) {
            return $userNotificationSetting->hasChannel($channel);
        }

        return true;
    }
}
