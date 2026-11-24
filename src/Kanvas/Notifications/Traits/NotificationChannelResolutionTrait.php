<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Enums\PushProviderEnum;
use Kanvas\Notifications\Models\NotificationTypes;

trait NotificationChannelResolutionTrait
{
    public array $channels = ['mail'];

    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Set notification channels using slugs (mail, email, push, expo, sms, database, twilio).
     * Validates each slug against NotificationChannelEnum.
     */
    public function setChannels(string ...$channels): self
    {
        foreach ($channels as $channel) {
            NotificationChannelEnum::getNotificationChannelBySlug($channel);
        }

        $this->channels = $channels;

        return $this;
    }

    /**
     * Resolve channel slugs ('mail', 'sms', 'push', etc.) to their Laravel channel
     * class implementations (e.g. TwilioSmsChannel::class). This is the single
     * resolution point — subclasses and callers should set $channels with slugs,
     * and this method handles the mapping via NotificationChannelEnum.
     */
    private function getNotificationChannels(): array
    {
        $channels = array_map(
            fn ($via): string => NotificationChannelEnum::getNotificationChannelBySlug($via),
            $this->channels()
        );

        if (array_filter($channels, PushProviderEnum::isPushChannel(...)) === []) {
            return $channels;
        }

        $pushChannel = PushProviderEnum::fromApp($this->app)->channel();

        return array_values(array_unique(array_map(
            fn (string $channel): string => PushProviderEnum::isPushChannel($channel) ? $pushChannel : $channel,
            $channels
        )));
    }

    /**
     * Only filter channels by user preferences when: we have channels to send,
     * a NotificationType is set (so we can look up settings), and the recipient is a user.
     */
    private function shouldFilterChannelsByUserSettings(object $notifiable): bool
    {
        return $this->channels() !== []
            && $this->type instanceof NotificationTypes
            && $notifiable instanceof UserInterface;
    }

    /**
     * Remove channels the user has explicitly disabled in their per-notification-type settings.
     * If no settings exist for this notification type, all channels are kept (enabled by default).
     */
    private function filterEnabledChannels(array $channels, UserInterface $notifiable): array
    {
        $enabledChannels = array_filter($channels, function ($channel) use ($notifiable) {
            foreach ($this->settingChannelIds($channel) as $channelId) {
                if ($notifiable->isNotificationSettingEnable($this->type, $this->app, $channelId)) {
                    return true;
                }
            }

            return false;
        });

        return array_values($enabledChannels);
    }

    /**
     * A user's push preference is stored under the PUSH or the EXPO id depending on the client that
     * saved it, so either one enables the app's single push provider.
     *
     * @return array<int, int>
     */
    private function settingChannelIds(string $channel): array
    {
        if (PushProviderEnum::isPushChannel($channel)) {
            return [
                NotificationChannelEnum::PUSH->value,
                NotificationChannelEnum::EXPO->value,
            ];
        }

        return [NotificationChannelEnum::getChannelIdByClassReference($channel)];
    }
}
