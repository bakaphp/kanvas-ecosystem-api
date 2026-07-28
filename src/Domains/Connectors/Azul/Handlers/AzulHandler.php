<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Handlers;

use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class AzulHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $auth1       = $this->data['auth1'] ?? null;
        $auth2       = $this->data['auth2'] ?? null;
        $store       = $this->data['store'] ?? null;
        $channel     = $this->data['channel'] ?? null;
        $baseUrl     = $this->data['base_url'] ?? null;
        $failoverUrl = $this->data['failover_url'] ?? null;

        if (empty($auth1) || empty($auth2) || empty($store) || empty($channel)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::AZUL_AUTH1->value, $auth1);
        $this->app->set(ConfigurationEnum::AZUL_AUTH2->value, $auth2);
        $this->app->set(ConfigurationEnum::AZUL_STORE->value, $store);
        $this->app->set(ConfigurationEnum::AZUL_CHANNEL->value, $channel);

        if ($baseUrl !== null) {
            $this->app->set(ConfigurationEnum::AZUL_BASE_URL->value, $baseUrl);
        }

        if ($failoverUrl !== null) {
            $this->app->set(ConfigurationEnum::AZUL_FAILOVER_URL->value, $failoverUrl);
        }

        $this->storeCertificate(ConfigurationEnum::AZUL_CERT, $this->data['cert'] ?? null);
        $this->storeCertificate(ConfigurationEnum::AZUL_KEY, $this->data['key'] ?? null);
        $this->storeCertificate(ConfigurationEnum::AZUL_CA, $this->data['ca'] ?? null);

        new Client($this->app, $this->company);

        return true;
    }

    /**
     * Accepts raw PEM or base64; stored encrypted at rest via setEncrypted().
     */
    private function storeCertificate(ConfigurationEnum $key, ?string $value): void
    {
        if (empty($value)) {
            return;
        }

        $pem = trim($value);

        if (! str_contains($pem, '-----BEGIN')) {
            $decoded = base64_decode($pem, true);

            if ($decoded === false || ! str_contains($decoded, '-----BEGIN')) {
                throw new ValidationException($key->value . ' must be PEM content or its base64 encoding.');
            }

            $pem = $decoded;
        }

        $this->app->setEncrypted($key->value, rtrim($pem) . "\n");
    }
}
