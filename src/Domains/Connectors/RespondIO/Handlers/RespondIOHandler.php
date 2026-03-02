<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class RespondIOHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $bearerToken = (string) ($this->data['bearer_token'] ?? '');

        if ($bearerToken === '') {
            throw new ValidationException('RespondIO bearer token is required');
        }

        $validated = Client::validateCredentials($bearerToken);

        if (! $validated) {
            throw new ValidationException('Failed to validate RespondIO connection');
        }

        $this->app->set(ConfigurationEnum::BEARER_TOKEN->value, $bearerToken);

        return true;
    }
}
