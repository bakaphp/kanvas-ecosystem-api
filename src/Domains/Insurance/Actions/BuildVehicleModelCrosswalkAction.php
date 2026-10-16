<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\Contracts\TargetedVehicleCatalogProviderInterface;
use Kanvas\Insurance\Contracts\VehicleModelCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleModel;
use Kanvas\Insurance\DataTransferObject\VehicleCatalogScan;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;

class BuildVehicleModelCrosswalkAction
{
    private const ANY_YEAR = 0;

    public const SOURCE_BUILDER = 'builder';

    public function __construct(
        protected Insurer $insurer,
        protected VehicleModelCatalogProviderInterface|TargetedVehicleCatalogProviderInterface $provider,
        protected array $vehicles,
    ) {
    }

    public function execute(): int
    {
        $scan = $this->scan();

        if ($scan->entries === [] && $scan->scannedPlanCodes === []) {
            return 0;
        }

        $confirmed = $this->confirmedEntries($scan->entries);

        $models = [];
        $modelsWithVersions = [];

        foreach ($confirmed as $entry) {
            $this->upsert($entry);
            $model = $this->modelKey($entry);
            $models[$model] = true;

            if ($entry->version !== '') {
                $modelsWithVersions[$model] = true;
            }
        }

        $this->deactivateStale($scan->scannedPlanCodes, $confirmed, $models, $modelsWithVersions);

        return count($models);
    }

    protected function scan(): VehicleCatalogScan
    {
        if ($this->provider instanceof TargetedVehicleCatalogProviderInterface) {
            return $this->provider->scanVehicleCatalog($this->vehicles);
        }

        $entries = $this->provider->vehicleModelEntries();

        return new VehicleCatalogScan($entries, $entries === [] ? [] : ['']);
    }

    protected function confirmedEntries(array $entries): array
    {
        $wanted = [];

        foreach ($this->vehicles as $vehicle) {
            $wanted[$vehicle->brandKey() . '|' . $vehicle->modelKey()] = true;
        }

        $confirmed = [];

        foreach ($entries as $entry) {
            $brandKey = VehicleKeyNormalizer::normalize($entry->brand);
            $modelKey = VehicleKeyNormalizer::normalize($entry->model);

            if ($brandKey === '' || $modelKey === '' || ! isset($wanted[$brandKey . '|' . $modelKey])) {
                continue;
            }

            $tuple = $this->tupleKey(
                $entry->planCode,
                $brandKey,
                $modelKey,
                VehicleKeyNormalizer::normalize($entry->version)
            );
            $current = $confirmed[$tuple] ?? null;

            if ($current === null || strnatcmp($this->tieBreaker($entry), $this->tieBreaker($current)) < 0) {
                $confirmed[$tuple] = $entry;
            }
        }

        return $confirmed;
    }

    protected function tieBreaker(ExternalVehicleModel $entry): string
    {
        return $entry->versionId ?? $entry->externalId;
    }

    protected function tupleKey(
        string $planCode,
        string $brandKey,
        string $modelKey,
        string $versionKey
    ): string {
        return $planCode . '|' . $brandKey . '|' . $modelKey . '|' . $versionKey;
    }

    protected function modelKey(ExternalVehicleModel $entry): string
    {
        return $entry->planCode
            . '|' . VehicleKeyNormalizer::normalize($entry->brand)
            . '|' . VehicleKeyNormalizer::normalize($entry->model);
    }

    protected function upsert(ExternalVehicleModel $entry): void
    {
        $row = InsuranceVehicleModelMap::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'insurers_id' => $this->insurer->getId(),
                'plan_code' => $entry->planCode,
                'brand_key' => VehicleKeyNormalizer::normalize($entry->brand),
                'model_key' => VehicleKeyNormalizer::normalize($entry->model),
                'version_key' => VehicleKeyNormalizer::normalize($entry->version),
                'year' => self::ANY_YEAR,
            ]);

        if ($row->exists && ($row->is_deleted || $row->source !== self::SOURCE_BUILDER)) {
            return;
        }

        $row->apps_id = $this->insurer->apps_id;
        $row->external_model_id = $entry->externalId;
        $row->external_brand_id = $entry->brandId;
        $row->external_version_id = $entry->versionId;
        $row->source = self::SOURCE_BUILDER;
        $row->active = true;
        $row->save();
    }

    protected function deactivateStale(
        array $scannedPlanCodes,
        array $confirmed,
        array $models,
        array $modelsWithVersions
    ): void {
        if ($scannedPlanCodes === []) {
            return;
        }

        $stale = [];

        foreach (array_chunk($this->vehicles, 200) as $chunk) {
            $rows = InsuranceVehicleModelMap::query()
                ->notDeleted()
                ->where('insurers_id', $this->insurer->getId())
                ->where('year', self::ANY_YEAR)
                ->where('source', self::SOURCE_BUILDER)
                ->where('active', true)
                ->whereIn('plan_code', $scannedPlanCodes)
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $vehicle) {
                        $query->orWhere(function ($pair) use ($vehicle): void {
                            $pair->where('brand_key', $vehicle->brandKey())
                                ->where('model_key', $vehicle->modelKey());
                        });
                    }
                })
                ->get(['id', 'plan_code', 'brand_key', 'model_key', 'version_key']);

            foreach ($rows as $row) {
                $model = $row->plan_code . '|' . $row->brand_key . '|' . $row->model_key;
                $tuple = $this->tupleKey(
                    $row->plan_code,
                    $row->brand_key,
                    $row->model_key,
                    $row->version_key
                );
                $modelGone = ! isset($models[$model]);
                $versionGone = isset($modelsWithVersions[$model]) && ! isset($confirmed[$tuple]);

                if ($modelGone || $versionGone) {
                    $stale[] = $row->getKey();
                }
            }
        }

        foreach (array_chunk($stale, 500) as $ids) {
            InsuranceVehicleModelMap::query()->whereIn('id', $ids)->update(['active' => false]);
        }
    }
}
