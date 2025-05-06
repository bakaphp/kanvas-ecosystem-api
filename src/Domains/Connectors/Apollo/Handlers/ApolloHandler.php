<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Handlers;

use Kanvas\Connectors\Apollo\Client;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class ApolloHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apolloApiKey = $this->data['apollo_api_key'] ?? null;

        if (empty($apolloApiKey)) {
            throw new ValidationException('Apollo API key is required.');
        }

        // Temporarily store the key in the app config for the Apollo\Client to pick it up
        $this->app->set(ConfigurationEnum::APOLLO_API_KEY->value, $apolloApiKey);

        try {
            // Use Apollo Client to attempt a harmless call and validate the API key
            $client = new Client($this->app);
            $client->post('/people/match', [
                'email' => 'doesnotexist@kanvas.dev',
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Unauthorized')) {
                throw new ValidationException('Invalid Apollo API key.');
            }

            throw new ValidationException('Apollo key validation failed: ' . $e->getMessage());
        }

        return true;
    }
}
