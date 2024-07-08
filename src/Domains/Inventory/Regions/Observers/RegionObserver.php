<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;

class RegionObserver
{
    public function creating(Regions $region): void
    {
        $defaultRegion = $region::getDefault($region->company);

        // if default already exist remove its default
        if ($region->is_default && $defaultRegion) {
            $defaultRegion->is_default = false;
            $defaultRegion->saveQuietly();
        }

        if (! $region->is_default && ! $defaultRegion) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Region');
        }
    }

    public function updating(Regions $region): void
    {
        $defaultRegion = Regions::getDefault($region->company);

        // if default already exist remove its default
        if ($defaultRegion &&
            $region->is_default &&
            $region->getId() != $defaultRegion->getId()
        ) {
            $defaultRegion->is_default = false;
            $defaultRegion->saveQuietly();
        } elseif ($defaultRegion &&
            ! $region->is_default &&
            $region->getId() == $defaultRegion->getId()
        ) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Region');
        }
    }

    public function deleting(Regions $region): void
    {
        if ($region->hasDependencies()) {
            throw new ValidationException('Can\'t delete, Region has warehouses associated');
        }

        $defaultRegion = $region::getDefault($region->company);
        $hasOtherDefault = $defaultRegion = Regions::fromCompany($region->company)
                        ->where('id', '!=', $region->getId())
                        ->where('is_default', 0)
                        ->first();

        if ($defaultRegion->getId() == $region->getId() && $hasOtherDefault) {
            throw new ValidationException('Can\'t delete, you have to have at least one default Region');
        } else {
            // change the default
            $hasOtherDefault->is_default = true;
            $hasOtherDefault->saveQuietly();
        }
    }
}
