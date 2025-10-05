<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class MindeeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $webhookSigningKey = $this->data['webhook_signing_key'] ?? null;

        if (empty($webhookSigningKey)) {
            throw new ValidationException('Webhook signing key is required for Mindee.');
        }

        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, $webhookSigningKey);

        return $this->company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value) === $webhookSigningKey;
    }
}
