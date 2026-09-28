<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

class PushOrganizationDeleteAction
{
    public function __construct(
        protected Organization $organization,
    ) {
    }

    /**
     * A no-op (not a failure) when the Organization was never pushed to Salesforce in the first
     * place — nothing to delete there.
     */
    public function execute(): bool
    {
        $externalId = $this->organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);

        if (! $externalId) {
            return false;
        }

        Client::getInstance($this->organization->app, $this->organization->company)
            ->delete('Account', (string) $externalId);

        return true;
    }
}
