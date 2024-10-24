<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Models\BaseModel;

trait DefaultTrait
{
    /**
     * get default entity of the model.
     */
    public static function getDefault(CompanyInterface $company, ?AppInterface $app = null): ?BaseModel
    {
        $query = self::where('companies_id', $company->getId())
                ->where('is_default', 1);
        if ($app) {
            $query->where('apps_id', $app->getId());
        }

        return $query->first();
    }
}
