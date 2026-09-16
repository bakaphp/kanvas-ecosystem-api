<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Odoo\Actions\Concerns\ParsesOdooPayload;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Expects a `res.partner` payload with `is_company = true` — the caller filters, this doesn't
 * re-check. `is_company = false` belongs to `PullPeopleAction`.
 */
class PullOrganizationAction
{
    use ParsesOdooPayload;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $odooId,
    ) {
    }

    public function execute(): Organization
    {
        $lockKey = 'odoo_partner_sync:' . $this->app->getId() . ':' . $this->company->getId() . ':' . $this->odooId;

        return Cache::lock($lockKey, 10)->block(5, function () {
            return DB::connection('crm')->transaction(function () {
                /** @var Organization|null $organization */
                $organization = Organization::getByCustomFieldTransactionSafe(
                    CustomFieldEnum::ODOO_ACCOUNT_ID->value,
                    $this->odooId,
                    $this->company,
                );

                if ($organization === null) {
                    $organization = new Organization();
                    $organization->apps_id = $this->app->getId();
                    $organization->companies_id = $this->company->getId();
                    $organization->users_id = $this->company->user->getId();
                }

                $organization->name = $this->payloadString('name') ?? $organization->name ?? 'Unknown Organization';
                $organization->phone = $this->payloadString('phone') ?? $organization->phone;

                // Never fireWorkflow on this write — an Organization synced in from Odoo must not
                // re-trigger an outbound push that syncs it right back (same anti-loop rule as
                // the Salesforce connector's Pull actions).
                $organization->disableWorkflows();
                $organization->saveOrFail();

                $organization->set(CustomFieldEnum::ODOO_ACCOUNT_ID->value, $this->odooId);

                return $organization;
            });
        });
    }
}
