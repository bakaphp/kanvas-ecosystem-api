<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Services;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\UsersNotificationsSettings;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class AppUserMailingEligibilityService
{
    public const string NOTIFICATION_TYPE = 'marketing';

    public const string REASON_UNKNOWN_OR_DELETED = 'unknown_or_deleted';
    public const string REASON_INACTIVE = 'inactive';
    public const string REASON_BANNED = 'banned';
    public const string REASON_NO_EMAIL = 'no_email';
    public const string REASON_MARKETING_DISABLED = 'marketing_disabled';
    public const string REASON_MUTED_ALL = 'muted_all';

    private const int SLICE_SIZE = 1000;

    private ?array $publishedTypeIds = null;

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    public function partition(array $userIds): array
    {
        $eligible = [];
        $excluded = $this->emptyBreakdown();

        foreach (array_chunk(array_values(array_unique($userIds)), self::SLICE_SIZE) as $slice) {
            $users = Users::query()
                ->whereIn('id', $slice)
                ->get(['id', 'email', 'is_deleted', 'user_active', 'banned'])
                ->keyBy('id');
            $profiles = $this->profiles($slice);
            $marketingOff = $this->usersWithMarketingDisabled($slice);
            $mutedAll = $this->usersWhoMutedEverything($slice);

            foreach ($slice as $userId) {
                $reason = $this->exclusionReason(
                    $users->get($userId),
                    $profiles->get($userId),
                    isset($marketingOff[$userId]),
                    isset($mutedAll[$userId]),
                );

                if ($reason === null) {
                    $eligible[] = $userId;

                    continue;
                }

                $excluded[$reason]++;
            }
        }

        return ['eligible' => $eligible, 'excluded' => $excluded];
    }

    public function emptyBreakdown(): array
    {
        return [
            self::REASON_UNKNOWN_OR_DELETED => 0,
            self::REASON_INACTIVE => 0,
            self::REASON_BANNED => 0,
            self::REASON_NO_EMAIL => 0,
            self::REASON_MARKETING_DISABLED => 0,
            self::REASON_MUTED_ALL => 0,
        ];
    }

    private function exclusionReason(
        ?Users $user,
        ?UsersAssociatedApps $profile,
        bool $marketingDisabled,
        bool $mutedAll,
    ): ?string {
        if ($user === null || $user->is_deleted || ($profile !== null && $profile->is_deleted)) {
            return self::REASON_UNKNOWN_OR_DELETED;
        }

        $isActive = $profile !== null ? $profile->isActive() : $user->isActive();
        if (! $isActive) {
            return self::REASON_INACTIVE;
        }

        if ($user->isBanned() || ($profile !== null && $profile->isBanned())) {
            return self::REASON_BANNED;
        }

        if (Str::trimToNull($user->email) === null) {
            return self::REASON_NO_EMAIL;
        }

        if ($mutedAll) {
            return self::REASON_MUTED_ALL;
        }

        return $marketingDisabled ? self::REASON_MARKETING_DISABLED : null;
    }

    private function profiles(array $userIds): Collection
    {
        return UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->whereIn('users_id', $userIds)
            ->get(['users_id', 'is_deleted', 'is_active', 'banned'])
            ->keyBy('users_id');
    }

    private function usersWithMarketingDisabled(array $userIds): array
    {
        $type = NotificationTypes::query()
            ->where('apps_id', $this->app->getId())
            ->where('name', self::NOTIFICATION_TYPE)
            ->where('is_deleted', 0)
            ->first();

        if ($type === null) {
            return [];
        }

        $disabled = [];
        $settings = UsersNotificationsSettings::query()
            ->where('apps_id', $this->app->getId())
            ->where('notifications_types_id', $type->getId())
            ->whereIn('users_id', $userIds)
            ->get();

        foreach ($settings as $setting) {
            if (! $setting->isEnable() || ! $setting->hasChannel(NotificationChannelEnum::MAIL->value)) {
                $disabled[(int) $setting->users_id] = true;
            }
        }

        return $disabled;
    }

    private function usersWhoMutedEverything(array $userIds): array
    {
        $typeIds = $this->publishedTypeIds();

        if ($typeIds === []) {
            return [];
        }

        $muted = UsersNotificationsSettings::query()
            ->where('apps_id', $this->app->getId())
            ->whereIn('users_id', $userIds)
            ->whereIn('notifications_types_id', $typeIds)
            ->groupBy('users_id')
            ->havingRaw('COUNT(DISTINCT notifications_types_id) >= ?', [count($typeIds)])
            ->havingRaw('SUM(is_enabled) = 0')
            ->pluck('users_id');

        return array_fill_keys($muted->map(fn (mixed $id): int => (int) $id)->all(), true);
    }

    private function publishedTypeIds(): array
    {
        return $this->publishedTypeIds ??= NotificationTypes::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', 0)
            ->where('is_published', 1)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
