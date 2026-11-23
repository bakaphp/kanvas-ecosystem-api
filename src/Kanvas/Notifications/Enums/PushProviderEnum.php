<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use NotificationChannels\Expo\ExpoChannel;

/**
 * An app delivers push through exactly one provider. Sending the same notification through both
 * reaches a device twice, so the 'push' and 'expo' slugs both resolve to whichever the app uses.
 */
enum PushProviderEnum: string
{
    case ONESIGNAL = 'onesignal';
    case EXPO = 'expo';

    public static function fromApp(AppInterface $app): self
    {
        $setting = $app->get(AppSettingsEnums::PUSH_PROVIDER->getValue());

        return self::tryFrom(strtolower(trim((string) $setting))) ?? self::ONESIGNAL;
    }

    public function channel(): string
    {
        return match ($this) {
            self::ONESIGNAL => OneSignalNotificationChannel::class,
            self::EXPO => ExpoChannel::class,
        };
    }

    public static function isPushChannel(string $channel): bool
    {
        return in_array($channel, [OneSignalNotificationChannel::class, ExpoChannel::class], true);
    }
}
