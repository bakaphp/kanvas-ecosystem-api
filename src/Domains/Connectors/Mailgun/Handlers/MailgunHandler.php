<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class MailgunHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $webhookSigningKey = $this->data['webhook_signing_key'] ?? null;
        $apiKey = (string) ($this->data['api_key'] ?? '');
        $domain = strtolower(trim((string) ($this->data['domain'] ?? '')));

        if (empty($webhookSigningKey)) {
            throw new ValidationException('Webhook signing key is required for Mailgun.');
        }

        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, $webhookSigningKey);

        if ($apiKey !== '') {
            $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);
        }

        if ($domain !== '') {
            // Rejecting a domain that isn't on the account here is the difference between a clear
            // setup error and agent mailboxes that silently never receive anything.
            new Client($this->app)->getDomain($domain);

            $this->company->set(ConfigurationEnum::DOMAIN->value, $domain);
        }

        return $this->company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value) === $webhookSigningKey;
    }
}
