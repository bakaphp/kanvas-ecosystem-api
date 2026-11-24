<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Concerns;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Laravel\Ai\Enums\Lab;
use Override;

/**
 * A one-shot structured call that needs no Agent row. An Agent row, when one exists, still wins
 * (prompt, provider, model edited from the agents UI). Without one, the company (then app) setting
 * named by llmConfigSettingKey() may point at any of the tenant's LLM configs, so a cheaper model
 * needs no Agent row; the app setting named by modelSettingKey() picks a Gemini model on the
 * tenant's own key; defaultModel() is the floor.
 */
trait ResolvesTenantLlmWithoutAgent
{
    private ?AgentLlmConfig $tenantLlmConfig = null;
    private bool $tenantLlmConfigResolved = false;

    abstract protected function llmConfigSettingKey(): string;

    abstract protected function modelSettingKey(): string;

    abstract protected function defaultModel(): string;

    public function setConfigurationWithoutAgent(Apps $app, Companies $company): void
    {
        $this->app = $app;
        $this->company = $company;
    }

    #[Override]
    protected function selectedLlmConfig(): ?AgentLlmConfig
    {
        if ($this->agentRecord !== null) {
            return parent::selectedLlmConfig();
        }

        if (! $this->tenantLlmConfigResolved) {
            $this->tenantLlmConfigResolved = true;
            $configId = (int) ($this->company?->get($this->llmConfigSettingKey())
                ?? $this->app?->get($this->llmConfigSettingKey())
                ?? 0);

            $this->tenantLlmConfig = $configId > 0 && $this->app !== null && $this->company !== null
                ? AgentProviderService::tenantConfig($this->app, $this->company, $configId)
                : null;
        }

        return $this->tenantLlmConfig;
    }

    #[Override]
    protected function getProvider(): ?Lab
    {
        return parent::getProvider() ?? Lab::Gemini;
    }

    #[Override]
    protected function getModel(): ?string
    {
        return parent::getModel()
            ?? Str::trimToNull((string) $this->app?->get($this->modelSettingKey()))
            ?? $this->defaultModel();
    }
}
