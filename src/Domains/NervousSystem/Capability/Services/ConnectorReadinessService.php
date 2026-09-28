<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\FinancialModelingPrep\Services\FinancialModelingPrepReadinessService;
use Kanvas\Connectors\GoogleSheets\Services\GoogleSheetsReadinessService;
use Kanvas\Connectors\Jina\Services\JinaReadinessService;
use Kanvas\Connectors\Tavily\Services\TavilyReadinessService;
use Kanvas\NervousSystem\Capability\Contracts\ProvidesConnectorReadiness;
use Kanvas\NervousSystem\Capability\DataTransferObject\ConnectorReadiness;
use Throwable;

/**
 * Maps a catalog tool back to the connector it needs, and answers whether that connector is set up.
 *
 * **Direction of travel: this class should go away, and `integration_companies` should be the single
 * source.** That row is written by `IntegrationsMutation`, which validates the config and runs the
 * connector's own `setup()` before stamping ACTIVE or FAILED — so it records a connection that was
 * actually proven, per company. This class only checks that an app-level config key is non-empty,
 * which proves nothing about whether it authenticates, and answers for a whole app rather than for
 * the company asking.
 *
 * It still exists because the two cover nearly disjoint sets. Of 27 integrations only `tavily` and
 * `jina` have both; the other 25 have only a row, and `GoogleSheets` and `FinancialModelingPrep` have
 * only a provider here — they are configured by app key directly and never pass through the setup
 * mutation, so nothing would answer for them today.
 *
 * So: do not add a fifth provider to work around a missing integration row. Give the connector a real
 * `integrations` row and a handler `setup()`, which is the shape everything is meant to converge on.
 */
class ConnectorReadinessService
{
    /** @var list<class-string<ProvidesConnectorReadiness>> */
    private const array PROVIDERS = [
        GoogleSheetsReadinessService::class,
        JinaReadinessService::class,
        TavilyReadinessService::class,
        FinancialModelingPrepReadinessService::class,
    ];

    /** @var array<string, ProvidesConnectorReadiness>|null Keyed by tool area, built once per instance. */
    private ?array $byArea = null;

    /**
     * The connector behind a tool handler, or null when the tool needs no external service (most of
     * the catalog — CRM, plans, inventory all run against our own database).
     */
    public function forHandler(?string $handler, AppInterface $app): ?ConnectorReadiness
    {
        $area = $this->areaFromHandler($handler);

        if ($area === null) {
            return null;
        }

        $provider = $this->providersByArea()[$area] ?? null;

        if ($provider === null) {
            return null;
        }

        try {
            return $provider->readiness($app);
        } catch (Throwable $e) {
            // A broken probe must not turn a capability question into a failed turn: an unknown
            // readiness reads as "no opinion", which is what null already means everywhere else here.
            report($e);

            return null;
        }
    }

    /**
     * `Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ReadGoogleSheetTool` → `GoogleSheets`.
     * Both tool trees share the segment, so one area name covers a connector's Neuron and Laravel
     * tools alike.
     */
    private function areaFromHandler(?string $handler): ?string
    {
        if ($handler === null || $handler === '') {
            return null;
        }

        return preg_match('/\\\\Tools\\\\([^\\\\]+)\\\\/', $handler, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @return array<string, ProvidesConnectorReadiness>
     */
    private function providersByArea(): array
    {
        if ($this->byArea !== null) {
            return $this->byArea;
        }

        $byArea = [];

        foreach (self::PROVIDERS as $class) {
            $provider = new $class();

            foreach ($provider->toolAreas() as $area) {
                $byArea[$area] = $provider;
            }
        }

        return $this->byArea = $byArea;
    }
}
