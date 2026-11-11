<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Contracts\AppInterface;
use Berkayk\OneSignal\OneSignalClient;
use Exception;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Notifications\Services\OneSignalService;

/**
 * Movipass ships two mobile apps under one Kanvas app, each with its own OneSignal app. The
 * app-level one_signal_* pair belongs to the users app; the technicians app has its own pair.
 */
class TechniciansOneSignalService extends OneSignalService
{
    public function __construct(AppInterface $app)
    {
        $appId = $app->get(ConfigurationEnum::ONE_SIGNAL_TECHNICIANS_APP_ID->value);
        $restApiKey = $app->get(ConfigurationEnum::ONE_SIGNAL_TECHNICIANS_REST_API_KEY->value);

        if (empty($appId) || empty($restApiKey)) {
            throw new Exception($app->name . ' OneSignal technicians App ID or Rest API Key is not set');
        }

        $this->app = $app;
        $this->oneSignalAppId = $appId;
        $this->oneSignalClient = new OneSignalClient(
            appId: $appId,
            restApiKey: $restApiKey,
            userAuthKey: '',
        );
    }
}
