<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Companies\Enums\ConfigurationEnum;
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
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Spatie\LaravelData\DataCollection;

class ProcessElevenLabsAgentWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $phone = isset($payload['phone']) ? (string) $payload['phone'] : null;

        if ($phone === null || $phone === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Phone number is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $people = $this->findPeopleByPhone($normalizedPhone);

        if (! $people) {
            $people = $this->createPeopleFromPhone($normalizedPhone);
        }

        $lead = LeadsRepository::getPeopleActiveLead($people);

        if (! $lead) {
            $lead = $this->createLeadFromPeople($people);
        }

        /* $agent = Agent::fromApp($app)
            ->fromCompany($company)
            ->where('name', AgentEnum::VOICE_OUTREACH->value)
            ->firstOrFail();
 */

        return [
            'lead' => [
                'id' => $lead->getId(),
                'uuid' => $lead->uuid,
                'company' => $lead->company->name,
                'firstname' => (string) $lead->people->firstname,
                'lastname' => (string) $lead->people->lastname,
                'email' => $lead->people->getEmails()->first()?->value,
                'phone' => $normalizedPhone,
                'title' => $lead->title,
                'pipeline' => $lead->pipeline?->name,
                'stage' => $lead->stage?->name,
                'current_date' => now()->toDateTimeString(),
                'ai_mode' => $lead->get(ConfigurationEnum::AI_MODE->value),
                'owner' => $lead->owner ? trim((string) $lead->owner->firstname . ' ' . (string) $lead->owner->lastname) : null,
            ],
            'voice_context' => [],
            'session' => [],
        ];
    }

    protected function findPeopleByPhone(string $phone): ?People
    {
        $digitsOnly = Str::sanitizePhoneNumber($phone);
        $normalizedPhone = Str::normalizePhoneNumber($phone);

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
            user: $this->receiver->user,
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
                user: $people->user,
                agent: $people->user,
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
            user: $people->user,
            title: $people->name . ' ElevenLabs Opp',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $people->app,
                $people->company->defaultBranch,
                $people->user,
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
