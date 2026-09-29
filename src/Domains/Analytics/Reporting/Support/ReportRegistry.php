<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionProviderInterface;
use Kanvas\Connectors\Intras\Reporting\IntrasReportDefinitionProvider;
use Kanvas\Exceptions\ValidationException;

/**
 * Which report models an app has.
 *
 * The registry knows nothing about any connector — it asks each provider, and a provider returns
 * nothing when its connector is not configured for that app. That scoping is what makes the
 * models safe to expose to an agent: one it never enabled is not discoverable, let alone
 * queryable, so there is no path from tool input to another tenant's tables.
 *
 * Adding a second connector's reporting means adding a provider here and nothing else.
 */
class ReportRegistry
{
    /**
     * @var array<int, class-string<ReportDefinitionProviderInterface>>
     */
    protected const array PROVIDERS = [
        IntrasReportDefinitionProvider::class,
    ];

    /**
     * @return array<string, ReportDefinitionInterface> keyed by model name, in provider order
     */
    public function for(AppInterface $app): array
    {
        $definitions = [];

        foreach (self::PROVIDERS as $providerClass) {
            /** @var ReportDefinitionProviderInterface $provider */
            $provider = new $providerClass();

            foreach ($provider->definitionsFor($app) as $definition) {
                $definitions[$definition->model()] = $definition;
            }
        }

        return $definitions;
    }

    public function find(AppInterface $app, string $model): ReportDefinitionInterface
    {
        $definitions = $this->for($app);

        if (! isset($definitions[$model])) {
            throw new ValidationException(sprintf(
                'Unknown report model "%s". Available: %s',
                $model,
                $definitions === [] ? '(none for this app)' : implode(', ', array_keys($definitions))
            ));
        }

        return $definitions[$model];
    }
}
