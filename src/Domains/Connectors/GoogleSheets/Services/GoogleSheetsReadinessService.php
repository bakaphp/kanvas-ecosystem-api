<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Services;

use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Capability\Services\SingleKeyConnectorReadiness;
use Override;

class GoogleSheetsReadinessService extends SingleKeyConnectorReadiness
{
    #[Override]
    public function slug(): string
    {
        return 'google-sheets';
    }

    #[Override]
    public function label(): string
    {
        return 'Google Sheets';
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function toolAreas(): array
    {
        return ['GoogleSheets'];
    }

    #[Override]
    protected function configKey(): string
    {
        return ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value;
    }

    #[Override]
    protected function checkName(): string
    {
        return 'service_account';
    }

    #[Override]
    protected function setupInstruction(): string
    {
        return 'Google Sheets is not configured for this app — an admin must set a Google service-account JSON key in';
    }
}
