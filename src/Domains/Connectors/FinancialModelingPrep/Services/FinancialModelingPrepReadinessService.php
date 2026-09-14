<?php

declare(strict_types=1);

namespace Kanvas\Connectors\FinancialModelingPrep\Services;

use Kanvas\Connectors\FinancialModelingPrep\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Capability\Services\SingleKeyConnectorReadiness;
use Override;

class FinancialModelingPrepReadinessService extends SingleKeyConnectorReadiness
{
    #[Override]
    public function slug(): string
    {
        return 'financial-modeling-prep';
    }

    #[Override]
    public function label(): string
    {
        return 'Financial Modeling Prep';
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function toolAreas(): array
    {
        return ['FinancialModelingPrep'];
    }

    #[Override]
    protected function configKey(): string
    {
        return ConfigurationEnum::FMP_API_KEY->value;
    }

    #[Override]
    protected function checkName(): string
    {
        return 'api_key';
    }

    #[Override]
    protected function setupInstruction(): string
    {
        return 'Financial Modeling Prep is not configured for this app — an admin must set';
    }
}
