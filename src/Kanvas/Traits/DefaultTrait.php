<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Models\BaseModel;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

trait DefaultTrait
{
    /**
     * get default entity of the model.
     * Falls back to app-global (companies_id = 0) if no company-specific default exists.
     */
    public static function getDefault(CompanyInterface $company, ?AppInterface $app = null): ?BaseModel
    {
        $query = self::where('companies_id', $company->getId())
                ->where('is_default', 1);
        if ($app) {
            $query->where('apps_id', $app->getId());
        }

        $result = $query->first();

        if ($result !== null) {
            return $result;
        }

        // Fall back to app-global default (companies_id = 0) only for companies with the flag enabled
        if (! ($app ?? app(Apps::class))->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            return null;
        }

        $globalQuery = self::where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('is_default', 1);
        if ($app) {
            $globalQuery->where('apps_id', $app->getId());
        }

        return $globalQuery->first();
    }
}
