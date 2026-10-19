<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Reynolds\Contracts\CrmLeadDriver;
use Kanvas\Connectors\Reynolds\DataTransferObject\CrmLeadSync;
use Kanvas\Connectors\Reynolds\Services\LeadCatalogResolver;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Creates the lead of a CRM prospect, or brings an existing one up to date. Only what the CRM gave is
 * touched, and the lead's own title is never rewritten. Workflows stay off: the caller fires the
 * ones it wants once everything is saved.
 */
class SaveCrmLeadAction
{
    public function __construct(
        protected readonly CrmLeadSync $data,
        protected readonly CrmLeadDriver $driver,
        protected readonly People $people,
        protected readonly ?Lead $lead,
        protected readonly ?int $ownerId
    ) {
    }

    public function execute(): Lead
    {
        $lead = $this->lead ?? $this->newLead();
        $isNew = ! $lead->exists;
        $info = $this->data->lead;
        $catalog = new LeadCatalogResolver($this->data->app, $this->data->company);

        $lead->leads_types_id = $catalog->typeId(
            Str::trimToNull($info?->type?->name),
            $isNew ? null : (int) $lead->leads_types_id
        );
        $lead->leads_sources_id = $catalog->sourceId(
            Str::trimToNull($info?->source?->name),
            $isNew ? null : (int) $lead->leads_sources_id
        );
        $lead->leads_status_id = $catalog->statusId(
            $this->driver->resolveStatusName(Str::trimToNull($info?->status)),
            $lead->leads_status_id ? (int) $lead->leads_status_id : null
        );

        if ($this->ownerId !== null) {
            $lead->leads_owner_id = $this->ownerId;
        }

        $lead->people_id = $this->people->getId();

        if ($info?->description !== null) {
            $lead->description = $info->description;
        }

        $this->fillContactColumns($lead);

        $lead->disableWorkflows();
        $lead->saveOrFail();

        return $lead;
    }

    private function newLead(): Lead
    {
        $lead = new Lead();
        $lead->apps_id = $this->data->app->getId();
        $lead->users_id = $this->data->user->getId();
        $lead->companies_id = $this->data->company->getId();
        $lead->companies_branches_id = $this->data->branch->getId();
        $lead->firstname = $this->people->firstname;
        $lead->lastname = $this->people->lastname;
        $lead->title = trim($this->people->firstname . ' ' . $this->people->lastname);
        $lead->pipeline_stage_id = 0;

        return $lead;
    }

    /**
     * The email and phone columns of the lead copy the people's; they are filled when empty, never
     * replaced.
     */
    private function fillContactColumns(Lead $lead): void
    {
        if (empty($lead->email)) {
            $lead->email = $this->people->getEmails()->first()?->value;
        }

        if (empty($lead->phone)) {
            $lead->phone = ($this->people->getCellPhones()->first() ?? $this->people->getPhones()->first())?->value;
        }
    }
}
