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
            $query = Templates::notDeleted()
                ->whereIn('apps_id', [AppEnums::LEGACY_APP_ID->getValue(), $app->getId()])
                ->where('name', $name);

            // If company is provided, filter by its ID, otherwise default to 0
            $companyId = $company ? $company->getId() : AppEnums::GLOBAL_COMPANY_ID->getValue();
            $query->where('companies_id', $companyId);

            return $query->orderBy('apps_id', 'desc')->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Template not found - ' . $name);
        }
    }
}
