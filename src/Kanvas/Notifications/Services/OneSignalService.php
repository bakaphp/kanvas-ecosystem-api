<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Berkayk\OneSignal\OneSignalClient;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Repositories\UsersLinkedSourcesRepository;

class OneSignalService
{
    protected OneSignalClient $oneSignalClient;
    protected string $oneSignalAppId;
    protected bool $hasAppleDevices = false;

    public function __construct(
        protected AppInterface $app,
    ) {
        $this->oneSignalAppId = $app->get(AppSettingsEnums::ONE_SIGNAL_APP_ID->getValue());
        $oneSignalRestApiKey = $app->get(AppSettingsEnums::ONE_SIGNAL_REST_API_KEY->getValue());
        $this->oneSignalClient = new OneSignalClient($this->oneSignalAppId, $oneSignalRestApiKey, '');
    }

    protected function getDevicesIds(UserInterface $user): array
    {
        $deviceIds = [];

        try {
            $appleLinkSource = UsersLinkedSourcesRepository::getAppleLinkedSource($user);
            $deviceIds[] = $appleLinkSource->source_users_id_text;
            $this->hasAppleDevices = true;
        } catch(ModelNotFoundException $e) {
        }

        try {
            $androidLinkSource = UsersLinkedSourcesRepository::getAndroidLinkedSource($user);
            $deviceIds[] = $androidLinkSource->source_users_id_text;
        } catch(ModelNotFoundException $e) {
        }

        return $deviceIds;
    }

    public function sendNotificationToUser(
        string $message,
        UserInterface $user,
        ?string $url = null,
        ?array $data = null,
        ?array $buttons = null,
        ?string $schedule = null,
        ?string $headings = null,
        ?string $subtitle = null
    ): void {
        $contents = [
            'en' => $message,
        ];

        $devicesIds = $this->getDevicesIds($user);

        if (empty($devicesIds)) {
            return;
        }

        $params = [
            'app_id' => $this->oneSignalAppId,
            'contents' => $contents,
            'include_player_ids' => $this->getDevicesIds($user),
        ];

        $disableIosNotificationBadge = (bool) $user->get('disable_ios_badge_count');
        //if IOS add badge
        if ($this->hasAppleDevices && ! $disableIosNotificationBadge) {
            $params['ios_badgeType'] = 'Increase';
            $params['ios_badgeCount'] = 1;
        }

        if (isset($url)) {
            $params['url'] = $url;
        }

        if (isset($data)) {
            $params['data'] = $data;
        }

        if (isset($buttons)) {
            $params['buttons'] = $buttons;
        }

        if (isset($schedule)) {
            $params['send_after'] = $schedule;
        }

        if (isset($headings)) {
            $params['headings'] = [
                'en' => $headings,
            ];
        }

        if (isset($subtitle)) {
            $params['subtitle'] = [
                'en' => $subtitle,
            ];
        }

        $this->oneSignalClient->sendNotificationCustom($params);
    }
}
