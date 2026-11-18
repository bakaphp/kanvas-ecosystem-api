<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;

/**
 * App settings live in Redis and the ecosystem connection, neither of which a test transaction
 * rolls back, so any class using this must be #[Group('serial')] and restore in tearDown.
 */
trait TogglesRegionalAssistance
{
    private array $originalRegionalSettings = [];

    protected function rememberRegionalAssistanceSettings(Apps $app): void
    {
        $settings = [
            ConfigurationEnum::ROADSIDE_REGIONAL_ASSISTANCE,
            ConfigurationEnum::ROADSIDE_TARIFFS,
            ConfigurationEnum::ROADSIDE_TARIFF_VERSIONS,
        ];

        foreach ($settings as $setting) {
            $this->originalRegionalSettings[$setting->value] = $app->get($setting->value);
        }

        $app->del(ConfigurationEnum::ROADSIDE_TARIFFS->value);
        $app->del(ConfigurationEnum::ROADSIDE_TARIFF_VERSIONS->value);
    }

    protected function toggleRegionalAssistance(Apps $app, bool $enabled): void
    {
        $enabled
            ? $app->set(ConfigurationEnum::ROADSIDE_REGIONAL_ASSISTANCE->value, 1)
            : $app->del(ConfigurationEnum::ROADSIDE_REGIONAL_ASSISTANCE->value);
    }

    protected function restoreRegionalAssistanceSettings(Apps $app): void
    {
        foreach ($this->originalRegionalSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }
    }
}
