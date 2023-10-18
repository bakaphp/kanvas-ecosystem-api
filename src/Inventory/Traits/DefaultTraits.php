<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Models\BaseModel;

trait DefaultTraits
{
    /**
     * get default entity of the model.
     */
    public static function getDefault(CompanyInterface $company): ?BaseModel
    {
        return self::where('companies_id', $company->getId())
        ->where('is_default', 1)
        ->first();
    }
}
