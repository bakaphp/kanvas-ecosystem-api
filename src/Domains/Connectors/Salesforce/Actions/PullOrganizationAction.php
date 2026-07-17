<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

class PullOrganizationAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): Organization
    {
        $lockKey = 'salesforce_account_sync:' . $this->app->getId() . ':' . $this->company->getId() . ':' . $this->salesforceId;

        return Cache::lock($lockKey, 10)->block(5, function () {
            return DB::connection('crm')->transaction(function () {
                /** @var Organization|null $organization */
                $organization = Organization::getByCustomFieldTransactionSafe(
                    CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
                    $this->salesforceId,
                    $this->company,
                );

                if ($organization === null) {
                    $organization = new Organization();
                    $organization->apps_id = $this->app->getId();
                    $organization->companies_id = $this->company->getId();
                    $organization->users_id = $this->company->user->getId();
                }

                $organization->name = (string) ($this->payload['Name'] ?? $organization->name ?? 'Unknown Account');
                $organization->phone = $this->payload['Phone'] ?? $organization->phone;
                $organization->total_employees = isset($this->payload['NumberOfEmployees'])
                    ? (int) $this->payload['NumberOfEmployees']
                    : ($organization->total_employees ?? 0);

                // Never call fireWorkflow on this write — the anti-loop rule for inbound
                // Salesforce sync: an Organization synced from Salesforce must not re-trigger the
                // outbound PushOrganizationActivity that syncs it right back.
                $organization->disableWorkflows();
                $organization->saveOrFail();

                $organization->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, $this->salesforceId);

                return $organization;
            });
        });
    }
}
