<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Square;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Square\Enums\ConfigurationEnum;
use Square\Environment;
use Square\SquareClient;

class Client
{
    public function __construct(
        protected AppInterface $app,
    ) {
    }

    public function getClient(bool $isProduction = true): SquareClient
    {
        return new SquareClient([
            'accessToken' => $this->app->get(ConfigurationEnum::ACCESS_TOKEN->value),
            'environment' => $isProduction ? Environment::PRODUCTION : Environment::SANDBOX, // Change to Environment::PRODUCTION for live mode
        ]);
    }
}
