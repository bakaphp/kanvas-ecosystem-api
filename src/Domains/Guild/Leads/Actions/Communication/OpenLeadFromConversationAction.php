<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions\Communication;

use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeByNameAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver as LeadReceiverData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\DataCollection;

/**
 * A new lead for a person who wrote in on a messaging channel. Whether they already have one is the
 * caller's question; this only opens it, the same way for every channel.
 */
final class OpenLeadFromConversationAction
{
    /**
     * @param array<string, mixed> $filing how the webhook receiver says its leads are filed:
     *                                     `lead_type` (a name, else Warm; opened when the company
     *                                     lacks it), `receiver_id` (the lead receiver whose rotation
     *                                     owns them) and `pipeline_id`
     */
    public function __construct(
        private readonly People $people,
        private readonly string $source,
        private readonly string $title,
        private readonly array $filing = []
    ) {
    }

    public function execute(): Lead
    {
        $people = $this->people;
        $leadTypeName = (string) ($this->filing['lead_type'] ?? 'Warm');
        $leadType = new CreateLeadTypeByNameAction($people->app, $people->company, $leadTypeName)->execute();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                $this->source,
                true,
                $this->source
            )
        )->execute();

        $leadReceiver = $this->configuredReceiver() ?? new CreateLeadReceiverAction(
            new LeadReceiverData(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $people->user,
                agent: $people->user,
                name: 'Agent',
                source: 'AI Agent',
                isDefault: false,
                lead_sources_id: $leadSource->getId(),
                lead_types_id: $leadType->getId()
            )
        )->execute();

        return new CreateLeadAction(new LeadData(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $this->title,
            pipeline_stage_id: $this->configuredPipeline()?->firstStage?->getId() ?? 0,
            people: new PeopleData(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $people->user,
                firstname: $people->firstname,
                contacts: Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $people->lastname,
                id: $people->id
            ),
            leads_owner_id: $leadReceiver->rotation?->getAgent()?->id ?? 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId()
        ))->execute();
    }

    private function configuredReceiver(): ?LeadReceiver
    {
        if (! isset($this->filing['receiver_id'])) {
            return null;
        }

        return LeadReceiver::query()
            ->fromApp($this->people->app)
            ->fromCompany($this->people->company)
            ->notDeleted()
            ->find((int) $this->filing['receiver_id']);
    }

    private function configuredPipeline(): ?Pipeline
    {
        if (! isset($this->filing['pipeline_id'])) {
            return null;
        }

        return Pipeline::query()
            ->fromApp($this->people->app)
            ->fromCompany($this->people->company)
            ->notDeleted()
            ->find((int) $this->filing['pipeline_id']);
    }
}
