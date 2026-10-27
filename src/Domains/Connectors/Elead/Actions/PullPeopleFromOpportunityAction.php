<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\DataTransferObject\Lead as LeadData;
use Kanvas\Connectors\Elead\Entities\Lead as LeadEntity;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

/**
 * Contacts are merged, not replaced: a number captured in Kanvas (an inbound SMS, an agent tool)
 * is not something eLead can vouch for, so a pull must never delete it. The update runs with
 * workflows off because the people-updated workflow pushes back to eLead, and a pull must not
 * turn into a push.
 */
class PullPeopleFromOpportunityAction
{
    public function __construct(
        protected Lead $lead,
        protected LeadEntity $opportunity,
        protected bool $fresh = false,
    ) {
    }

    public function execute(): ?People
    {
        if ($this->opportunity->customerId === null || $this->opportunity->customerId === '' || $this->lead->people === null) {
            return null;
        }

        try {
            $customer = $this->opportunity->customer($this->fresh);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $peopleData = LeadData::peopleFromCustomer($customer, $this->lead->user);
        $peopleData->mergeContacts = true;

        $update = new UpdatePeopleAction($this->lead->people, $peopleData);
        $update->runWorkflow = false;

        return $update->execute();
    }
}
