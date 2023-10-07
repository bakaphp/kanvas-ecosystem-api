<?php

declare(strict_types=1);

namespace Kanvas\Templates\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Templates\Models\Templates;

class TemplatesRepository
{
    /**
     * Retrieve email template by name.
     */
    public static function getByName(string $name, AppInterface $app): Templates
    {
        // $companyId = userData->currentCompanyId() ?? 0;

        try {
            return Templates::notDeleted()
                                // ->where('companies_id',$companyId)
                                ->whereIn('apps_id', [AppEnums::LEGACY_APP_ID->getValue(), $app->getId()])
                                ->where('name', $name)
                                ->orderBy('id', 'desc')
                                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Template not found - ' . $name);
        }
    }
}
