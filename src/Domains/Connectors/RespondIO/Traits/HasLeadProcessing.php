<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Traits;

use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver as LeadReceiverModel;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\DataCollection;

trait HasLeadProcessing
{
    protected function createLeadFromPeople(
        People $people,
        array $receiverConfiguration
    ): Lead {
        $activeLead = LeadsRepository::getPeopleActiveLead($people);

        if ($activeLead) {
            return $activeLead;
        }

        $leadTypeName = $receiverConfiguration['lead_type'] ?? 'Warm';

        $leadType = LeadType::fromApp($people->app)
            ->fromCompany($people->company)
            ->where('name', $leadTypeName)
            ->first();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                'RespondIO',
                true,
                'RespondIO'
            )
        )->execute();

        $receiverId = $receiverConfiguration['receiver_id'] ?? null;

        if ($receiverId !== null) {
            $leadReceiver = LeadReceiverModel::fromApp($people->app)
                ->fromCompany($people->company)
                ->where('id', $receiverId)
                ->where('is_deleted', 0)
                ->firstOrFail();
        } else {
            $leadReceiver = new CreateLeadReceiverAction(
                new LeadReceiver(
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
        }

        $pipelineId = $receiverConfiguration['pipeline_id'] ?? null;
        $pipeline = null;

        if ($pipelineId !== null) {
            $pipeline = Pipeline::fromApp($people->app)
                ->fromCompany($people->company)
                ->where('id', $pipelineId)
                ->where('is_deleted', 0)
                ->first();
        }

        $leadData = new LeadData(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $people->name . ' RespondIO Opp',
            pipeline_stage_id: $pipeline?->firstStage?->getId() ?? 0,
            people: new PeopleDTO(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $people->user,
                firstname: $people->firstname,
                contacts: Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $people->lastname,
                id: $people->id,
                runWorkflow: false
            ),
            leads_owner_id: $leadReceiver->rotation ? $leadReceiver->rotation->getAgent()->id : 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId()
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags([
            'respondio',
            'ai-agent',
        ]);
        $lead->set('sub_source', 'RespondIO');

        return $lead;
    }
}
