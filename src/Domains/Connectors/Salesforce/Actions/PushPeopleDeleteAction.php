<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

class PushPeopleDeleteAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    /**
     * A no-op (not a failure) when the People was never pushed to Salesforce in the first place —
     * nothing to delete there.
     */
    public function execute(): bool
    {
        $externalId = $this->people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value);

        if (! $externalId) {
            return false;
        }

        Client::getInstance($this->people->app, $this->people->company)
            ->delete('Contact', (string) $externalId);

        return true;
    }
}
