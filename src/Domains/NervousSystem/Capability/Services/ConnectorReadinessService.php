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
 * An explicit list rather than attribute discovery: there are a handful of these, adding one is a
 * single line, and a reader can see the whole set at once. If it grows past ~15, move it to an
 * `AttributeClassDiscovery` subclass the way the tool catalog does.
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
