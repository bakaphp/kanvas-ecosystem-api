<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Providers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use NotificationChannels\Twilio\TwilioProvider;
use Illuminate\Support\Facades\Schema;

class NotificationTwilioProvider extends TwilioProvider
{
    public function register(): void
    {
        if (Schema::hasTable('apps_settings')) {
            $app = $this->app->make(Apps::class);

            config([
                'twilio-notification-channel.account_sid' => $app->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value),
                'twilio-notification-channel.auth_token' => $app->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value),
            ]);
            parent::register();
        }
    }
}
