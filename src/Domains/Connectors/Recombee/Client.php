<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Contracts\BaseClient;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Recombee\RecommApi\Client as RecommApiClient;

class Client extends BaseClient
{
    protected static ?RecommApiClient $instance;

    public function __construct(
        protected AppInterface $app,
        ?string $recombeeDatabase = null,
        ?string $recombeeApiKey = null,
        ?string $recombeeRegion = null
    ) {
        $recombeeDatabase = (string) ($recombeeDatabase ?? $app->get(ConfigurationEnum::RECOMBEE_DATABASE->value));
        $recombeeApiKey = (string) ($recombeeApiKey ?? $app->get(ConfigurationEnum::RECOMBEE_API_KEY->value));
        $recombeeRegion = (string) ($recombeeRegion ?? $app->get(ConfigurationEnum::RECOMBEE_REGION->value) ?? 'ca-east');

        if (empty($recombeeDatabase) || empty($recombeeApiKey)) {
            throw new ValidationException('Recombee database and api key are required');
        }

        $this->instance = new RecommApiClient(
            $recombeeDatabase,
            $recombeeApiKey,
            [
                'region' => $recombeeRegion,
            ]
        );
    }

    protected static function createInstance(AppInterface $app, CompanyInterface $company, KanvasRegions $region)
    {
        $recombeeDatabase = (string) ($recombeeDatabase ?? $app->get(ConfigurationEnum::RECOMBEE_DATABASE->value));
        $recombeeApiKey = (string) ($recombeeApiKey ?? $app->get(ConfigurationEnum::RECOMBEE_API_KEY->value));
        $recombeeRegion = (string) ($recombeeRegion ?? $app->get(ConfigurationEnum::RECOMBEE_REGION->value) ?? 'ca-east');

        [$recombeeDatabase, $recombeeApiKey, $recombeeRegion] = self::getKeys($company, $app, $region);

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

    public static function getInstance(AppInterface $app, CompanyInterface $company, KanvasRegions $region)
    {
        if (! isset(self::$instance)) {
            return self::$instance = self::createInstance($app, $company, $region);
            self::cleanupOldInstances();
        }

        return self::$instance;
    }

    public function getClient(): RecommApiClient
    {
        return $this->instance;
    }

    public static function getKeys(CompanyInterface $company, AppInterface $app, KanvasRegions $region): array
    {
        $credentialKey = RecombeeConfigurationService::generateCredentialKey($company, $app, $region);
        $credential = $company->get($credentialKey);

        if (empty($credential) || ! is_array($credential)) {
            throw new ValidationException(
                sprintf(
                    'Recombee keys are not set for company %s (ID: %d) on region %s',
                    $company->name,
                    $company->id,
                    $region->name
                )
            );
        }

        return [
            $credential[ConfigurationEnum::RECOMBEE_DATABASE->value],
            $credential[ConfigurationEnum::RECOMBEE_API_KEY->value],
            $credential[ConfigurationEnum::RECOMBEE_REGION->value]
        ];
    }

    /**
     * Create and validate a new Shopify SDK instance.
     */
    public static function getInstanceValidation(
        AppInterface $app,
        CompanyInterface $company,
        KanvasRegions $region
    ): RecommApiClient {
        return self::$instance = self::getInstance($app, $company, $region);
    }

    private static function cleanupOldInstances(): void
    {
        if (self::$instance) {
            self::$instance = null;
        }
    }
}
