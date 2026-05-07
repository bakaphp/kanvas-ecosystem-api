<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Spatie\LaravelData\DataCollection;

abstract class ProcessElevenLabsWebhookJob extends ProcessWebhookJob
{
    protected function resolveUser(): Users
    {
        return $this->receiver->company->getAiAgentUser() ?? $this->receiver->user;
    }

    protected function resolveLeadByPhone(string $phone): Lead
    {
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $people = $this->findPeopleByPhone($normalizedPhone, $phone)
            ?? $this->createPeopleFromPhone($normalizedPhone);

        return LeadsRepository::getPeopleActiveLead($people)
            ?? $this->createLeadFromPeople($people);
    }

    protected function findPeopleByPhone(string $normalizedPhone, string $rawPhone): ?People
    {
        $digitsOnly = Str::sanitizePhoneNumber($rawPhone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: array_unique([$digitsOnly, $normalizedPhone]),
        );

        $allCustomers = $query->get();

        return $allCustomers->first(function (People $customer): bool {
            return LeadsRepository::getPeopleActiveLead($customer) !== null;
        }) ?? $allCustomers->first();
    }

    protected function createPeopleFromPhone(string $phone): People
    {
        $contactData = [
            [
                'value' => $phone,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDto(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->resolveUser(),
            firstname: $phone,
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: '',
            custom_fields: [
                'elevenlabs_phone' => $phone,
            ],
            tags: ['elevenlabs', 'voice-agent'],
        );

        return new CreatePeopleAction($peopleDto)->execute();
    }

    protected function createLeadFromPeople(People $people): Lead
    {
        $leadType = LeadType::fromApp($people->app)
            ->fromCompany($people->company)
            ->where('name', 'Warm')
            ->firstOrFail();

        $user = $this->resolveUser();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                'elevenlabs',
                true,
                'elevenlabs',
            )
        )->execute();

        $leadReceiver = new CreateLeadReceiverAction(
            new LeadReceiver(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $user,
                agent: $user,
                name: 'ElevenLabs Agent',
                source: 'ElevenLabs Voice Agent',
                isDefault: false,
                lead_sources_id: $leadSource->getId(),
                lead_types_id: $leadType->getId(),
            )
        )->execute();

        $leadData = new LeadData(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $user,
            title: $people->name . ' ElevenLabs Opp',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $people->app,
                $people->company->defaultBranch,
                $user,
                (string) $people->firstname,
                Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                Address::collect([], DataCollection::class),
                (string) $people->lastname,
                $people->id,
            ),
            leads_owner_id: 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId(),
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags(['elevenlabs', 'voice-agent']);

        return $lead;
    }
}
