<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Meta\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

class MetaWhatsAppIntegrationService
{
    /**
     * On: the company completed the integration in this app, it was not switched off, and it still
     * has a number: the setting names its default one for as long as it has any.
     */
    public static function isActive(AppInterface $app, Companies $company): bool
    {
        if ($company->get(ConfigurationEnum::PHONE_NUMBER_ID->value) === null) {
            return false;
        }

        return IntegrationsCompany::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('is_active', 1)
            ->whereHas('integration', fn (Builder $query) => $query->where('name', IntegrationsEnum::META_WHATSAPP->value))
            ->whereHas('status', fn (Builder $query) => $query->where('slug', StatusEnum::ACTIVE->value))
            ->exists();
    }
}
