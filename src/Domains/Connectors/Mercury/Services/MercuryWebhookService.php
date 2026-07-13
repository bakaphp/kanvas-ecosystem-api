<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Enums\WebhookEventEnum;

class MercuryWebhookService extends MercuryApiService
{
    /**
     * Registers the endpoint and persists the signing secret.
     *
     * The secret comes back ONLY in this response — never on GET, never on update. If we don't store it here
     * it's unrecoverable, and the only remedy is to delete the webhook and register a new one. So the secret
     * is stored before anything else can fail.
     */
    public function register(string $url): array
    {
        $response = $this->client->post('webhooks', [
            'url' => $url,
            'eventTypes' => WebhookEventEnum::subscribed(),
        ]);

        $secret = (string) ($response['secret'] ?? '');

        if ($secret !== '') {
            $this->company->set(ConfigurationEnum::WEBHOOK_SECRET->value, $secret);
        }

        $this->company->set(ConfigurationEnum::WEBHOOK_ID->value, (string) ($response['id'] ?? ''));

        return $response;
    }

    public function delete(string $webhookId): void
    {
        $this->client->delete("webhooks/{$webhookId}");

        $this->company->set(ConfigurationEnum::WEBHOOK_ID->value, '');
        $this->company->set(ConfigurationEnum::WEBHOOK_SECRET->value, '');
    }

    public function registeredWebhookId(): ?string
    {
        $id = (string) $this->company->get(ConfigurationEnum::WEBHOOK_ID->value);

        return $id !== '' ? $id : null;
    }
}
