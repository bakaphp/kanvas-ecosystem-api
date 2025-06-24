<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationSettingsRepository;

trait HasNotificationSettings
{
    abstract public function getId(): mixed;

    /**
     * Check if the user has the notification setting enabled for the specified channel.
     */
    public function isNotificationSettingEnable(
        NotificationTypes $type,
        AppInterface $app,
        int $channel = NotificationChannelEnum::MAIL->value
    ): bool {
        $userNotificationSetting = NotificationSettingsRepository::getNotificationSettingsByType(
            $this,
            $app,
            $type
        );

        // If no setting exists, notifications are enabled by default
        if (! $userNotificationSetting) {
            return true;
        }

        // Setting exists: check if enabled and has the specified channel
        return $userNotificationSetting->isEnable() && $userNotificationSetting->hasChannel($channel);
    }
}
