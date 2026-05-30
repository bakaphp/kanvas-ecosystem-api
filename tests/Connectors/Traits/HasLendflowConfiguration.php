<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Lendflow\Client;
use Kanvas\Connectors\Lendflow\Enums\ConfigurationEnum;

trait HasLendflowConfiguration
{
    public function getLendflowClient(AppInterface $app, Companies $company): Client
    {
        $company->set(ConfigurationEnum::API_KEY->value, $this->lendflowApiKey() ?? '');
        $company->set(
            ConfigurationEnum::WORKFLOW_TEMPLATE_ID->value,
            $this->lendflowWorkflowTemplateId() ?? ''
        );
        $company->set(ConfigurationEnum::USE_SANDBOX->value, 1);

        return new Client($app, $company);
    }

    protected function lendflowApiKey(): ?string
    {
        return $this->lendflowEnv('TEST_LENDFLOW_API_KEY');
    }

    protected function lendflowWorkflowTemplateId(): ?string
    {
        return $this->lendflowEnv('TEST_LENDFLOW_WORKFLOW_TEMPLATE_ID');
    }

    protected function lendflowApplicationId(): ?string
    {
        return $this->lendflowEnv('TEST_LENDFLOW_APPLICATION_ID');
    }

    /**
     * Reads $_ENV / $_SERVER / getenv() in that order because Dotenv::createImmutable
     * (used in this codebase's TestCase) populates $_ENV/$_SERVER but not getenv() —
     * so values defined in .env are invisible to a bare getenv() call.
     */
    private function lendflowEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
