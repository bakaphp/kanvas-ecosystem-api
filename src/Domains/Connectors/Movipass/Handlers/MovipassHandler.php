<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Override;

class MovipassHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = $this->data['baseUrl'];
        $clientId = $this->data['clientId'];
        $secret = $this->data['secret'];

        $expiringReservationMin = $this->data['expiringReservationMin'] ?? ConfigurationEnum::EXPIRING_RESERVATION_MIN->value;
        $expiringReservationMax = $this->data['expiringReservationMax'] ?? ConfigurationEnum::EXPIRING_RESERVATION_MAX->value;
        $notificationPushTemplate = $this->data['notificationPushTemplate'] ?? ConfigurationEnum::NOTIFICATION_PUSH_TEMPLATE->value;
        $notificationEmailTemplate = $this->data['notificationEmailTemplate'] ?? ConfigurationEnum::NOTIFICATION_EMAIL_TEMPLATE->value;
        $lowBalancePushTemplate = $this->data['lowBalancePushTemplate'] ?? ConfigurationEnum::LOW_BALANCE_PUSH_TEMPLATE->value;
        $lowBalanceEmailTemplate = $this->data['lowBalanceEmailTemplate'] ?? ConfigurationEnum::LOW_BALANCE_EMAIL_TEMPLATE->value;

        if (empty($baseUrl) || empty($clientId) || empty($secret)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::EXPIRING_RESERVATION_MIN_FIELD->value, $expiringReservationMin);
        $this->app->set(ConfigurationEnum::EXPIRING_RESERVATION_MAX_FIELD->value, $expiringReservationMax);
        $this->app->set(ConfigurationEnum::NOTIFICATION_PUSH_TEMPLATE_FIELD->value, $notificationPushTemplate);
        $this->app->set(ConfigurationEnum::NOTIFICATION_EMAIL_TEMPLATE_FIELD->value, $notificationEmailTemplate);
        $this->app->set(ConfigurationEnum::LOW_BALANCE_PUSH_TEMPLATE_FIELD->value, $lowBalancePushTemplate);
        $this->app->set(ConfigurationEnum::LOW_BALANCE_EMAIL_TEMPLATE_FIELD->value, $lowBalanceEmailTemplate);

        return true;
    }
}
