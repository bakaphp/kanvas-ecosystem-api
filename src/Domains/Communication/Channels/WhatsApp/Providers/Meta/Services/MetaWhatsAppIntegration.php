<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

class MetaWhatsAppIntegration
{
    /**
     * On: the company completed the integration in this app, it was not switched off, and the
     * number it set up is still on the company.
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
