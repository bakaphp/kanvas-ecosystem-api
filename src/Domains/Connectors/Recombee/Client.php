<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Recombee\RecommApi\Client as RecommApiClient;

class Client
{
    protected RecommApiClient $client;

    public function __construct(protected AppInterface $app)
    {
        $recombeeDatabase = $app->get(ConfigurationEnum::RECOMBEE_DATABASE->value);
        $recombeeApiKey = $app->get(ConfigurationEnum::RECOMBEE_API_KEY->value);
        $recombeeRegion = $app->get(ConfigurationEnum::RECOMBEE_REGION->value) ?? 'us-east';

        if (empty($recombeeDatabase) || empty($recombeeApiKey)) {
            throw new ValidationException('Recombee database and api key are required');
        }

        $this->client = new RecommApiClient(
            $recombeeDatabase,
            $recombeeApiKey,
            [
                'region' => $recombeeRegion,
            ]
        );
    }

    public function getClient(): RecommApiClient
    {
        return $this->client;
    }
}
