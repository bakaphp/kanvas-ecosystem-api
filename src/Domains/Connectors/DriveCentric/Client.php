<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps; // Import the Http class
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;

class Client
{
    public function __construct(
        protected Apps $app
    ) {
    }

    protected function makeClient(): PendingRequest
    {
        return Http::withUrlParameters([
            'endpoint' => $this->app->get(ConfigurationEnum::BASE_URL->value),
        ]);
    }

    protected function getToken(): string
    {
        $client = $this->makeClient();
        $response = $client->post('{+endpoint}/api/authentication/token', [
            'client_id' => $this->app->get(ConfigurationEnum::API_KEY->value),
            'client_secret' => $this->app->get(ConfigurationEnum::API_SECRET_KEY->value),
        ]);
        return $response->json('idToken');
    }

    public function getClient(): PendingRequest
    {
        $token = $this->getToken();

        return $this->makeClient()->withToken($token);
    }
}
