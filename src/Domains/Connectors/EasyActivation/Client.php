<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EasyActivation;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\EasyActivation\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    public GuzzleClient $client;
    public string $baseUri = 'https://easyactivations.com';

    public function __construct(
        protected AppInterface $app
    ) {
        $username = $this->app->get(ConfigurationEnum::EASY_ACTIVATION_USERNAME->value);
        $password = $this->app->get(ConfigurationEnum::EASY_ACTIVATION_PASSWORD->value);

        if (! $username || ! $password) {
            throw new ValidationException('Easy Activation credentials not set');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'auth' => [
                $username,
                $password,
            ],
        ]);
    }
}
