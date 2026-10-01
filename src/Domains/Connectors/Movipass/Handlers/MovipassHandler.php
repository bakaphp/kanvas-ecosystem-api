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
        $gracePeriodDays = $this->data['gracePeriodDays'] ?? 1;

        if (empty($baseUrl) || empty($clientId) || empty($secret)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::EXPIRING_RESERVATION_MIN_FIELD->value, $expiringReservationMin);
        $this->app->set(ConfigurationEnum::EXPIRING_RESERVATION_MAX_FIELD->value, $expiringReservationMax);
        $this->app->set(ConfigurationEnum::NOTIFICATION_PUSH_TEMPLATE_FIELD->value, $notificationPushTemplate);
        $this->app->set(ConfigurationEnum::NOTIFICATION_EMAIL_TEMPLATE_FIELD->value, $notificationEmailTemplate);
        $this->app->set(ConfigurationEnum::LOW_BALANCE_PUSH_TEMPLATE_FIELD->value, $lowBalancePushTemplate);
        $this->app->set(ConfigurationEnum::LOW_BALANCE_EMAIL_TEMPLATE_FIELD->value, $lowBalanceEmailTemplate);
        $this->app->set(ConfigurationEnum::GRACE_PERIOD_DAYS->value, $gracePeriodDays);

        $this->setupRoadsideAssistanceProvider();

        return true;
    }

    /**
     * The external roadside assistance provider is optional: only companies that dispatch through
     * it send these keys, so an absent value must leave the existing setting alone rather than
     * blanking a working configuration.
     */
    private function setupRoadsideAssistanceProvider(): void
    {
        $settings = [
            ConfigurationEnum::ROADSIDE_PROVIDER_BASE_URL->value => $this->data['roadsideProviderBaseUrl'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_API_TOKEN->value => $this->data['roadsideProviderApiToken'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_AUTH_HEADER->value => $this->data['roadsideProviderAuthHeader'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_AUTH_SCHEME->value => $this->data['roadsideProviderAuthScheme'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_CATALOG_CACHE_TTL->value => $this->data['roadsideProviderCatalogCacheTtl'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_POLL_INTERVAL->value => $this->data['roadsideProviderPollIntervalSeconds'] ?? null,
            ConfigurationEnum::ROADSIDE_PROVIDER_STATE_MAP->value => $this->data['roadsideProviderStateMap'] ?? null,
            ConfigurationEnum::ROADSIDE_MAX_RESCHEDULES->value => $this->data['roadsideMaxReschedules'] ?? null,
        ];

        foreach ($settings as $key => $value) {
            if ($value !== null && $value !== '') {
                $this->app->set($key, $value);
            }
        }
    }
}
