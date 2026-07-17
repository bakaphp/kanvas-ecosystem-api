<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Organizations\Models\Organization;

class PullDealAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): Deal
    {
        $lockKey = 'salesforce_opportunity_sync:' . $this->app->getId() . ':' . $this->company->getId() . ':' . $this->salesforceId;

        return Cache::lock($lockKey, 10)->block(5, function () {
            return DB::connection('crm')->transaction(function () {
                $organization = null;
                if (! empty($this->payload['AccountId'])) {
                    /** @var Organization|null $organization */
                    $organization = Organization::getByCustomFieldTransactionSafe(
                        CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
                        (string) $this->payload['AccountId'],
                        $this->company,
                    );
                }

                $people = null;
                if (! empty($this->payload['ContactId'])) {
                    /** @var People|null $people */
                    $people = People::getByCustomFieldTransactionSafe(
                        CustomFieldEnum::SALESFORCE_CONTACT_ID->value,
                        (string) $this->payload['ContactId'],
                        $this->company,
                    );
                }

                /** @var Deal|null $existing */
                $existing = Deal::getByCustomFieldTransactionSafe(
                    CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value,
                    $this->salesforceId,
                    $this->company,
                );

                $dealData = new DealData(
                    app: $this->app,
                    company: $this->company,
                    user: $this->company->user,
                    title: (string) ($this->payload['Name'] ?? ('Salesforce Opportunity ' . $this->salesforceId)),
                    description: $this->payload['Description'] ?? null,
                    organization: $organization,
                    people: $people,
                );

                $deal = $existing !== null
                    ? new UpdateDealAction($existing, $dealData, runWorkflow: false)->execute()
                    : new CreateDealAction($dealData, runWorkflow: false)->execute();

                $deal->set(CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value, $this->salesforceId);

                return $deal;
            });
        });
    }
}
