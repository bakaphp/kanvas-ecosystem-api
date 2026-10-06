<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Fixtures;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

final class SettingsOwningHandler extends BaseIntegration
{
    public const COMPANY_KEY = 'remove_integration_company_test_company_key';
    public const APP_KEY = 'remove_integration_company_test_app_key';

    #[Override]
    public function setup(): bool
    {
        $this->company->set(self::COMPANY_KEY, 'company-secret');
        $this->app->set(self::APP_KEY, 'app-secret');

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [self::COMPANY_KEY];
    }
}
