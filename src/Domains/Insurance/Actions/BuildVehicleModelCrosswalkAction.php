<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\Contracts\VehicleModelCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;

class BuildVehicleModelCrosswalkAction
{
    private const ANY_YEAR = 0;

    public const SOURCE_BUILDER = 'builder';

    public function __construct(
        protected Insurer $insurer,
        protected VehicleModelCatalogProviderInterface $provider,
        protected array $vehicles,
    ) {
    }

    public function execute(): int
    {
        $index = $this->indexCatalog();

        // An empty catalog means the fetch failed or was cut short, not that the insurer dropped every model.
        if ($index === []) {
            return 0;
        }

        $matchedKeys = [];
        $unmatchedVehicles = [];

        foreach ($this->vehicles as $vehicle) {
            $key = $vehicle->brandKey() . '|' . $vehicle->modelKey();
            $externalId = $index[$key] ?? null;

            if ($externalId === null) {
                $unmatchedVehicles[$key] = $vehicle;

                continue;
            }

            if (! isset($matchedKeys[$key])) {
                $matchedKeys[$key] = true;
                $this->upsert($vehicle, $externalId);
            }
        }

        $this->deactivate(array_values($unmatchedVehicles));

        return count($matchedKeys);
    }

    protected function indexCatalog(): array
    {
        $index = [];

        foreach ($this->provider->vehicleModelEntries() as $entry) {
            $brandKey = VehicleKeyNormalizer::normalize($entry->brand);
            $modelKey = VehicleKeyNormalizer::normalize($entry->model);

            if ($brandKey === '' || $modelKey === '') {
                continue;
            }

            $key = $brandKey . '|' . $modelKey;

            if (! isset($index[$key]) || strnatcmp($entry->externalId, $index[$key]) < 0) {
                $index[$key] = $entry->externalId;
            }
        }

        return $index;
    }

    protected function upsert(CanonicalVehicle $vehicle, string $externalId): void
    {
        $row = InsuranceVehicleModelMap::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'insurers_id' => $this->insurer->getId(),
                'brand_key' => $vehicle->brandKey(),
                'model_key' => $vehicle->modelKey(),
                'year' => self::ANY_YEAR,
            ]);

        if ($row->exists && ($row->is_deleted || $row->source !== self::SOURCE_BUILDER)) {
            return;
        }

        $row->apps_id = $this->insurer->apps_id;
        $row->external_model_id = $externalId;
        $row->source = self::SOURCE_BUILDER;
        $row->active = true;
        $row->save();
    }

    protected function deactivate(array $unmatchedVehicles): void
    {
        foreach (array_chunk($unmatchedVehicles, 200) as $chunk) {
            InsuranceVehicleModelMap::query()
                ->notDeleted()
                ->where('insurers_id', $this->insurer->getId())
                ->where('year', self::ANY_YEAR)
                ->where('source', self::SOURCE_BUILDER)
                ->where('active', true)
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $vehicle) {
                        $query->orWhere(function ($pair) use ($vehicle): void {
                            $pair->where('brand_key', $vehicle->brandKey())
                                ->where('model_key', $vehicle->modelKey());
                        });
                    }
                })
                ->update(['active' => false]);
        }
    }
}
