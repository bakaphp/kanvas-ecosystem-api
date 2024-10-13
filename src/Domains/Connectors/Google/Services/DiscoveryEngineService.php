<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Google\Cloud\DiscoveryEngine\V1\Client\RecommendationServiceClient;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class DiscoveryEngineService
{
    protected RecommendationServiceClient $client;
    protected array $googleClientConfig;
    protected array $googleRecommendationConfig;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $googleClientConfig = $this->app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $googleRecommendationConfig = $this->app->get(ConfigurationEnum::GOOGLE_RECOMMENDATION_CONFIG->value);

        if (! $googleClientConfig) {
            throw new ValidationException('Google client config not found for app ' . $this->app->name);
        }

        if (! $googleRecommendationConfig) {
            throw new ValidationException('Google recommendation config not found for app ' . $this->app->name);
        }

        $this->googleClientConfig = $googleClientConfig;
        $this->googleRecommendationConfig = $googleRecommendationConfig;
        $this->client = new RecommendationServiceClient([
            'credentials' => $this->googleClientConfig,
        ]);
    }
}
