<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tavily\Services;

use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Capability\Services\SingleKeyConnectorReadiness;
use Override;

class TavilyReadinessService extends SingleKeyConnectorReadiness
{
    #[Override]
    public function slug(): string
    {
        return 'tavily';
    }

    #[Override]
    public function label(): string
    {
        return 'Tavily web research';
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function toolAreas(): array
    {
        return ['Tavily'];
    }

    #[Override]
    protected function configKey(): string
    {
        return ConfigurationEnum::TAVILY_API_KEY->value;
    }

    #[Override]
    protected function checkName(): string
    {
        return 'api_key';
    }

    #[Override]
    protected function setupInstruction(): string
    {
        return 'Tavily is not configured for this app — an admin must set';
    }
}
