<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Lendflow\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class LendflowHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = (string) ($this->data['api_key'] ?? '');
        $workflowTemplateId = (string) ($this->data['workflow_template_id'] ?? '');
        $useSandbox = (bool) ($this->data['use_sandbox'] ?? false);

        if ($apiKey === '') {
            throw new ValidationException('Lendflow API key is required');
        }

        if ($workflowTemplateId === '') {
            throw new ValidationException('Lendflow workflow_template_id is required');
        }

        $this->company->set(ConfigurationEnum::API_KEY->value, $apiKey);
        $this->company->set(ConfigurationEnum::WORKFLOW_TEMPLATE_ID->value, $workflowTemplateId);
        $this->company->set(ConfigurationEnum::USE_SANDBOX->value, $useSandbox ? 1 : 0);

        return true;
    }
}
