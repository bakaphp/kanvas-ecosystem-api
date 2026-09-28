<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;

/**
 * Registers a People's interest in a Property on the Salesforce side, as a Location_Contact__c row
 * (Location__c ↔ Contact__c, Location_Contact_Type__c = 'Interested Party' — confirmed against real
 * production data: GA Group has no standard Lead object permission, so this junction object is their
 * functional "lead"). Dedups on the (Location__c, Contact__c) pair since there is no single-column
 * external id to upsert against, unlike Contact/Account.
 */
class PushPropertyInterestAction
{
    public function __construct(
        protected People $people,
        protected string $locationSalesforceId,
    ) {
    }

    public function execute(): ?string
    {
        $contactId = $this->people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value);

        if (! $contactId) {
            return null;
        }

        $this->assertValidSalesforceId($this->locationSalesforceId);
        $this->assertValidSalesforceId((string) $contactId);

        $client = Client::getInstance($this->people->app, $this->people->company);

        $existing = $client->query(
            "SELECT Id FROM Location_Contact__c WHERE Location__c = '{$this->locationSalesforceId}' "
            . "AND Contact__c = '{$contactId}' LIMIT 1"
        );

        if (! empty($existing['records'])) {
            return $existing['records'][0]['Id'];
        }

        return $client->create('Location_Contact__c', [
            'Location__c' => $this->locationSalesforceId,
            'Contact__c' => $contactId,
            'Location_Contact_Type__c' => 'Interested Party',
        ]);
    }

    /**
     * Both interpolated values are Salesforce ids we wrote ourselves (one from PullPropertyAction's
     * import, the other from PushPeopleAction's own upsert) — never user input — but a cheap shape
     * check before interpolating into SOQL costs nothing and rules out injection outright.
     */
    private function assertValidSalesforceId(string $id): void
    {
        if (! preg_match('/^[a-zA-Z0-9]{15,18}$/', $id)) {
            throw new ValidationException("Invalid Salesforce id: {$id}");
        }
    }
}
