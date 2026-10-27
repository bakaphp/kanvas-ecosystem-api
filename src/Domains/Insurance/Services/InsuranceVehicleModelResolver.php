<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleReference;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;

class InsuranceVehicleModelResolver
{
    public function resolve(AppInterface $app, string $provider, CanonicalVehicle $vehicle): ?string
    {
        return $this->find($app, $provider, '', '', $vehicle)?->external_model_id;
    }

    public function resolveReference(
        AppInterface $app,
        string $provider,
        string $planCode,
        CanonicalVehicle $vehicle
    ): ?ExternalVehicleReference {
        $row = $this->find($app, $provider, $planCode, $vehicle->versionKey(), $vehicle);

        if ($row === null) {
            return null;
        }

        return new ExternalVehicleReference(
            brandId: $row->external_brand_id,
            modelId: $row->external_model_id,
            versionId: $row->external_version_id,
        );
    }

    private function find(
        AppInterface $app,
        string $provider,
        string $planCode,
        string $versionKey,
        CanonicalVehicle $vehicle
    ): ?InsuranceVehicleModelMap {
        if (! $vehicle->isIdentifiable()) {
            return null;
        }

        return InsuranceVehicleModelMap::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('active', true)
            ->where('plan_code', $planCode)
            ->where('brand_key', $vehicle->brandKey())
            ->where('model_key', $vehicle->modelKey())
            ->where('version_key', $versionKey)
            ->whereIn('year', array_unique([$vehicle->year ?? 0, 0]))
            ->whereHas(
                'insurer',
                fn (Builder $insurer) => $insurer->where('provider', $provider)->where('is_deleted', 0)
            )
            ->orderByDesc('year')
            ->orderBy('id')
            ->first();
    }
}
