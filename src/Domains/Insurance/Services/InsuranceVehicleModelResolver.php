<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;

class InsuranceVehicleModelResolver
{
    public function resolve(AppInterface $app, string $provider, CanonicalVehicle $vehicle): ?string
    {
        if (! $vehicle->isIdentifiable()) {
            return null;
        }

        $row = InsuranceVehicleModelMap::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('active', true)
            ->where('brand_key', $vehicle->brandKey())
            ->where('model_key', $vehicle->modelKey())
            ->whereIn('year', array_unique([$vehicle->year ?? 0, 0]))
            ->whereHas(
                'insurer',
                fn ($insurer) => $insurer->where('provider', $provider)->where('is_deleted', 0)
            )
            ->orderByDesc('year')
            ->orderBy('id')
            ->first();

        return $row?->external_model_id;
    }
}
