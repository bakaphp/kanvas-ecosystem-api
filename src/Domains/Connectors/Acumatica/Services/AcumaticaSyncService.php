<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaSyncTarget;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\SyncEntityEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Owns the scheduled-sync state: which companies are opted in, and the per-entity incremental
 * high-watermark. The scheduler stays dumb — it asks this service for enabled targets and cursors.
 */
class AcumaticaSyncService
{
    /**
     * Every company that has explicitly opted in via the SYNC_ENABLED setting. Enablement is the
     * hard gate — nothing is synced for a company that isn't in this list.
     *
     * @return Collection<int, Companies>
     */
    public function enabledCompanies(): Collection
    {
        $companies = Companies::getByCustomFieldBuilder(ConfigurationEnum::SYNC_ENABLED->value, null)->get();

        $enabled = [];
        foreach ($companies as $company) {
            /** @var Companies $company */
            if ((bool) $company->get(ConfigurationEnum::SYNC_ENABLED->value)) {
                $enabled[] = $company;
            }
        }

        return collect($enabled);
    }

    /**
     * Resolve a company's stored sync settings into a runnable target, or null when it's disabled,
     * misconfigured, or the app it points at has no SQL connection configured.
     */
    public function resolveTarget(Companies $company): ?AcumaticaSyncTarget
    {
        if (! (bool) $company->get(ConfigurationEnum::SYNC_ENABLED->value)) {
            return null;
        }

        $appId = (int) $company->get(ConfigurationEnum::SYNC_APP_ID->value, 0);
        $acumaticaCompanyId = (int) $company->get(ConfigurationEnum::SYNC_COMPANY_ID->value, 0);

        if ($appId <= 0 || $acumaticaCompanyId <= 0) {
            return null;
        }

        try {
            /** @var Apps $app */
            $app = Apps::getById($appId);

            if (! SqlClient::isConfigured($app)) {
                return null;
            }

            $userId = (int) $company->get(ConfigurationEnum::SYNC_USER_ID->value, 0);
            /** @var Users $user */
            $user = $userId > 0 ? Users::getById($userId) : $company->user;

            $regionId = (int) $company->get(ConfigurationEnum::SYNC_REGION_ID->value, 0);
            /** @var Regions|null $region */
            $region = $regionId > 0
                ? Regions::getByIdFromCompanyApp($regionId, $company, $app)
                : Regions::fromApp($app)->fromCompany($company)->notDeleted()->first();

            return new AcumaticaSyncTarget($app, $company, $user, $region, $acumaticaCompanyId);
        } catch (Throwable) {
            return null;
        }
    }

    public function cursorFor(Companies $company, SyncEntityEnum $entity): ?Carbon
    {
        $value = $company->get($entity->cursorKey());

        return ! empty($value) ? Carbon::parse((string) $value) : null;
    }

    public function advanceCursor(Companies $company, SyncEntityEnum $entity, Carbon $to): void
    {
        $company->set($entity->cursorKey(), $to->toIso8601String());
    }
}
