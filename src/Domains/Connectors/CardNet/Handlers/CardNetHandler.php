<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Handlers;

use Kanvas\Connectors\CardNet\Client;
use Kanvas\Connectors\CardNet\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class CardNetHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $privateKey = $this->data['private_key'] ?? null;
        $publicKey = $this->data['public_key'] ?? null;
        $baseUrl = $this->data['base_url'] ?? null;

        if (empty($privateKey) || empty($publicKey) || empty($baseUrl)) {
            throw new ValidationException('CardNet setup requires private_key, public_key, and base_url');
        }

        $this->app->set(ConfigurationEnum::PRIVATE_KEY->value, $privateKey);
        $this->app->set(ConfigurationEnum::PUBLIC_KEY->value, $publicKey);
        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);

        new Client($this->app);

        return true;
    }
}
