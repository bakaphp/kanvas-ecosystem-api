<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jina\Services;

use Kanvas\Connectors\Jina\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Capability\Services\SingleKeyConnectorReadiness;
use Override;

class JinaReadinessService extends SingleKeyConnectorReadiness
{
    #[Override]
    public function slug(): string
    {
        return 'jina';
    }

    #[Override]
    public function label(): string
    {
        return 'Jina reader and search';
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function toolAreas(): array
    {
        return ['Jina'];
    }

    #[Override]
    protected function configKey(): string
    {
        return ConfigurationEnum::JINA_API_KEY->value;
    }

    #[Override]
    protected function checkName(): string
    {
        return 'api_key';
    }

    #[Override]
    protected function setupInstruction(): string
    {
        return 'Jina is not configured for this app — an admin must set';
    }
}
