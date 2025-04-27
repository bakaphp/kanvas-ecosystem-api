<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Connectors\Elead\DataTransferObject\Lead;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class SyncLeadByThirdPartyCustomFieldAction
{
    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(): ModelsLead
    {
        $customFields = $this->lead->custom_fields ?? [];
        $customFieldKeys = array_keys($customFields);
        $customFieldValues = array_values($customFields);

        if (empty($customFieldKeys[0]) || empty($customFieldValues[0])) {
            throw new ValidationException('Lead Missing Custom Fields Key and Value to find reference');
        }

        $lead = ModelsLead::getByCustomField(
            $customFieldKeys[0],
            $customFieldValues[0],
            $this->lead->branch->company,
        );

        $peopleSync = new SyncPeopleByThirdPartyCustomFieldAction($this->lead->people);
        $people = $peopleSync->execute();

        if ($lead === null) {
            $this->lead->people->id = $people->id;
            $createLead = new CreateLeadAction(
                $this->lead,
            );
            $lead = $createLead->execute();
        }

        $lead->firstname = $this->lead->people->firstname;
        $lead->lastname = $this->lead->people->lastname;
        $lead->email = $this->lead->people->getEmails()[0]['value'] ?? null;
        $lead->description = $this->lead->description;
        $lead->leads_status_id = $this->lead->status_id;
        $lead->leads_types_id = $this->lead->type_id;
        $lead->leads_sources_id = $this->lead->source_id;
        $lead->leads_owner_id = $this->lead->leads_owner_id;
        $lead->title = $this->lead->title;
        $lead->setCustomFields(
            $this->lead->custom_fields,
        );

        if (method_exists($lead, 'disableWorkflows')) {
            $lead->disableWorkflows();
        }

        $lead->saveOrFail();

        if (count($this->lead->followers)) {
            foreach ($this->lead->followers as $follower) {
                $follower->follow($lead);
            }
        }

        return $lead;
    }
}
