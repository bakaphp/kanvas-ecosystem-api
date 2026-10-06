<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideCatalogEntry;

/**
 * Our order statuses and the provider's state catalog are two independent vocabularies, so the map
 * between them is data, not code: an explicit per-app override wins, otherwise we match our status
 * slug against the labels the provider actually returned. That way a new provider state needs a
 * config row, not a deploy.
 */
final class RoadsideProviderStateMapper
{
    public function __construct(private readonly AppInterface $app)
    {
    }

    public function resolve(string $internalStatusSlug): ?RoadsideCatalogEntry
    {
        $entries = RoadsideCatalogEntry::collection(
            new RoadsideProviderCatalogService($this->app)->states(),
        );

        $needle = $this->overrideFor($internalStatusSlug) ?? $internalStatusSlug;

        foreach ($entries as $entry) {
            if ($entry->matches($needle)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Config shape: {"service_in_progress": "3", "service_completed": "Finished"} — the value is
     * matched against both the catalog id and its label.
     */
    private function overrideFor(string $internalStatusSlug): ?string
    {
        $map = $this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_STATE_MAP->value);

        if (is_string($map)) {
            $map = json_decode($map, true);
        }

        if (! is_array($map)) {
            return null;
        }

        $value = $map[$internalStatusSlug] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
