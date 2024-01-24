<?php

declare(strict_types=1);

namespace Kanvas\Templates\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Templates\Models\Templates;

class TemplatesRepository
{
    /**
     * Retrieve email template by name.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByName(string $name, AppInterface $app, ?CompanyInterface $company = null): Templates
    {
        try {
            // If company is provided, filter by its ID, otherwise default to 0
            $companyId = $company ? $company->getId() : AppEnums::GLOBAL_COMPANY_ID->getValue();

            return Templates::notDeleted()
                ->whereIn('apps_id', [AppEnums::LEGACY_APP_ID->getValue(), $app->getId()])
                ->where('name', $name)
                ->whereIn('companies_id', [$companyId, AppEnums::GLOBAL_COMPANY_ID->getValue()])
                ->orderByRaw('
                    CASE 
                        WHEN companies_id = ? AND apps_id = ? THEN 1
                        WHEN apps_id = ? THEN 2
                        ELSE 3
                    END', [$companyId, $app->getId(), $app->getId()])
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Template not found - ' . $name);
        }
    }
}
