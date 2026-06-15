<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Entities\Customer;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\Models\People;

/**
 * Maps a Reynolds Lead Update payload (the `Record` node) into a Kanvas People row.
 *
 * Expects the parsed array of a `rey_SalesAssistCRMPublishLeadUpdate.Record` element,
 * which contains either IndividualCustomer or BusinessCustomer plus Address,
 * PhoneNumbers, Email, and Consent.
 */
class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user
    ) {
    }

    public function execute(array $record): People
    {
        $peopleData = Customer::fromRecord($record)->toPeopleData(
            $this->app,
            $this->company->defaultBranch,
            $this->user
        );

        return new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();
    }
}
