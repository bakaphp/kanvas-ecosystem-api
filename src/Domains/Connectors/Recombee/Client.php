<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Recombee\RecommApi\Client as RecommApiClient;

class Client
{
    protected RecommApiClient $instance;

    public function __construct(
        protected AppInterface $app,
        protected ?string $recombeeDatabase = null,
        protected ?string $recombeeApiKey = null,
        protected ?string $recombeeRegion = null
    ) {
        $this->instance = $this->createInstance($app);
    }

    protected function createInstance(AppInterface $app): RecommApiClient
    {
        $recombeeDatabase = (string) ($this->recombeeDatabase ?? $app->get(ConfigurationEnum::RECOMBEE_DATABASE->value));
        $recombeeApiKey = (string) ($this->recombeeApiKey ?? $app->get(ConfigurationEnum::RECOMBEE_API_KEY->value));
        $recombeeRegion = (string) ($this->recombeeRegion ?? $app->get(ConfigurationEnum::RECOMBEE_REGION->value) ?? 'ca-east');

        if (empty($recombeeDatabase) || empty($recombeeApiKey)) {
            throw new ValidationException('Recombee database and api key are required');
        }

        return new RecommApiClient(
            $recombeeDatabase,
            $recombeeApiKey,
            [
                'region' => $recombeeRegion,
            ]
        );
    }

    public function getClient(): RecommApiClient
    {
        return $this->instance;
    }
}
