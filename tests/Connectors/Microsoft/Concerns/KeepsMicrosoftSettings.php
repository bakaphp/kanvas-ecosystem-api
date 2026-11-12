<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;

/**
 * App and company settings are not rolled back with the transaction (Redis plus another connection),
 * and they hold a developer's real Microsoft connection. Remember every key a test may touch and put
 * each one back afterwards.
 */
trait KeepsMicrosoftSettings
{
    /** @var list<array{0: Apps|Companies, 1: string, 2: mixed}> */
    private array $keptMicrosoftSettings = [];

    protected function keepMicrosoftSettings(Apps $app, Companies $company): void
    {
        $registration = [ConfigurationEnum::CLIENT_ID, ConfigurationEnum::CLIENT_SECRET, ConfigurationEnum::TENANT_ID];
        $connection = [
            ConfigurationEnum::ACCESS_TOKEN,
            ConfigurationEnum::REFRESH_TOKEN,
            ConfigurationEnum::TOKEN_EXPIRES_AT,
            ConfigurationEnum::GRANTED_SCOPES,
            ConfigurationEnum::ONEDRIVE_ROOT_FOLDER,
            ConfigurationEnum::LAST_SYNC_AT,
        ];
        $companySuffix = ConfigurationEnum::companySuffix($app, $company);

        foreach ($registration as $key) {
            $this->keepSetting($app, $key->value);
            $this->keepSetting($company, $key->value);
        }

        foreach ($connection as $key) {
            $this->keepSetting($app, $key->value . '-onedrive');
            $this->keepSetting($company, $key->value . $companySuffix);
        }

        foreach ([ConfigurationEnum::SCOPES->value, ConfigurationEnum::OAUTH_RETURN_URL->value, 'microsoft-oauth-redirect-url'] as $key) {
            $this->keepSetting($app, $key);
        }
    }

    protected function restoreMicrosoftSettings(): void
    {
        foreach ($this->keptMicrosoftSettings as [$owner, $key, $value]) {
            $value === null ? $owner->del($key) : $owner->set($key, $value);
        }

        $this->keptMicrosoftSettings = [];
    }

    private function keepSetting(Apps|Companies $owner, string $key): void
    {
        $this->keptMicrosoftSettings[] = [$owner, $key, $owner->get($key)];
    }
}
