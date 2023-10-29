<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Models\BaseModel;

trait DefaultTrait
{
    /**
     * get default entity of the model.
     */
    public static function getDefault(CompanyInterface $company): ?BaseModel
    {
        return self::fromCompany($company)
        ->where('is_default', 1)
        ->first();
    }
}
