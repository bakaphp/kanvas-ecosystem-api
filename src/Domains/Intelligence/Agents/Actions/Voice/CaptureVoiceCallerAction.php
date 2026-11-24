<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Intelligence\Agents\Actions\Voice\Concerns\RunsInAppScope;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceCallerLookupService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Leads\Services\ContactHistoryService;
use Kanvas\Intelligence\Sessions\Actions\UpdateLeadSessionsAction;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Throwable;

use function Sentry\captureException;

/**
 * Voice data plane: capture the caller the agent is talking to.
 *
 * The first call records a People and no lead, so the caller is recognized next time; a lead is
 * opened once they qualify (qualifiesForLead()). A call summary is appended to the contact history
 * (the lead's, or the People's before there is one), which the next call and the lead's SMS agent
 * session read back. Best-effort: returns a result the LLM can speak; never throws.
 */
class CaptureVoiceCallerAction
{
    use RunsInAppScope;

    private readonly ?string $name;
    private readonly ?string $interest;
    private readonly ?string $summary;
    private readonly ?string $appointmentPreference;

    public function __construct(
        private readonly Agent $agent,
        private readonly string $phone,
        ?string $name = null,
        ?string $interest = null,
        private readonly bool $interested = false,
        private readonly ?string $direction = null,
        ?string $summary = null,
        private readonly bool $wantsAppointment = false,
        ?string $appointmentPreference = null,
    ) {
        $this->name = Str::trimToNull($name);
        $this->interest = Str::trimToNull($interest);
        $this->summary = Str::trimToNull($summary);
        $this->appointmentPreference = Str::trimToNull($appointmentPreference);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        try {
            return $this->inApp($this->agent->app, fn (): array => $this->capture());
        } catch (Throwable $e) {
            captureException($e);
            // The spoken message is deliberately generic, so the reason is logged here.
            logger()->error('captureVoiceLead failed', [
                'phone' => $this->phone,
                'agent_id' => $this->agent->getId(),
                'direction' => $this->direction,
                'exception' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ['status' => 'error', 'message' => "I couldn't save the caller details just now."];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        $company = $this->agent->company;
        if ($company === null) {
            return ['status' => 'error', 'message' => 'No company is set for this agent.'];
        }

        $user = $company->getAiAgentUser() ?? $this->agent->user;
        $existing = VoiceCallerLookupService::find($this->agent->app, $company, $this->phone);
        $people = $existing ?? $this->createPeople($company, $user);
        $this->saveCallerDetails($people);

        $lead = LeadsRepository::getPeopleActiveLead($people);
        $createdLead = $lead === null && $this->qualifiesForLead(isReturning: $existing !== null);
        if ($createdLead) {
            $lead = $this->createLeadFromPeople($people, $user);
        }

        // Only a summary marks the end of a conversation; interest-only mid-call
        // captures would otherwise add several entries for one call.
        if ($this->summary !== null) {
            new ContactHistoryService()->record(
                entity: $lead ?? $people,
                channel: 'voice',
                direction: $this->direction,
                summary: $this->buildContextInfo(),
            );
            $this->refreshAgentSessions($lead);
        }

        return [
            'status' => 'success',
            'people_id' => $people->getId(),
            'lead_id' => $lead?->getId(),
            'created_lead' => $createdLead,
            'is_returning' => $existing !== null,
            'message' => $createdLead
                ? 'Saved the caller and created a lead.'
                : 'Saved the caller details.',
        ];
    }

    /**
     * Placing an outbound call is not itself a signal, so it needs clear intent; an inbound caller
     * also qualifies by calling a second time. Asking for an appointment is intent, whatever the
     * model set `interested` to.
     */
    private function qualifiesForLead(bool $isReturning): bool
    {
        $intent = $this->interested || $this->wantsAppointment;

        return $this->direction === 'outbound' ? $intent : ($isReturning || $intent);
    }

    private function createPeople(Companies $company, Users $user): People
    {
        $contactData = [[
            'value' => $this->phone,
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'weight' => 100,
        ]];

        $dto = new PeopleDto(
            app: $this->agent->app,
            branch: $company->defaultBranch,
            user: $user,
            firstname: $this->phone, // placeholder until we learn a real name
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: '',
            tags: ['voice-agent'],
        );

        return new CreatePeopleAction($dto)->execute();
    }

    private function saveCallerDetails(People $people): void
    {
        if ($this->name !== null) {
            $this->applyName($people);
        }
        if ($this->interest !== null) {
            $people->set('voice_interest', $this->interest);
        }
        if (! $this->wantsAppointment) {
            return;
        }

        $people->set('voice_wants_appointment', '1');
        if ($this->appointmentPreference !== null) {
            $people->set('voice_appointment_preference', $this->appointmentPreference);
        }
    }

    /**
     * Fills in the placeholder only: a name already on the contact is never overwritten.
     */
    private function applyName(People $people): void
    {
        if (! VoiceCallerLookupService::isPlaceholderName($people->firstname)) {
            return;
        }

        $parts = preg_split('/\s+/', $this->name, 2) ?: [];
        $people->firstname = $parts[0] ?? '';
        $people->lastname = $parts[1] ?? '';
        $people->saveOrFail();
    }

    private function createLeadFromPeople(People $people, Users $user): Lead
    {
        // "Warm" is a seeded default that older companies can lack; capture must not fail on it.
        $leadType = LeadType::firstOrCreate(
            [
                'apps_id' => $people->app->getId(),
                'companies_id' => $people->company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm',
                'is_active' => 1,
            ],
        );

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                app: $people->app,
                company: $people->company,
                leads_types_id: $leadType->getId(),
                name: 'voice',
                is_active: true,
                description: 'voice',
            )
        )->execute();

        $leadReceiver = new CreateLeadReceiverAction(
            new LeadReceiver(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $user,
                agent: $user,
                name: 'Voice Agent',
                source: 'Voice Agent',
                isDefault: false,
                lead_sources_id: $leadSource->getId(),
                lead_types_id: $leadType->getId(),
            )
        )->execute();

        $leadData = new LeadData(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $user,
            title: $this->leadTitle($people),
            pipeline_stage_id: 0,
            people: new PeopleDto(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $user,
                firstname: (string) $people->firstname,
                contacts: Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: (string) $people->lastname,
                id: $people->id,
            ),
            leads_owner_id: 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId(),
            description: $this->leadDescription(),
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags(['voice-agent']);

        return $lead;
    }

    /**
     * Who, how they came in and what they want, e.g.
     * "Rafael Lopez — Outbound voice call — interested in a 2024 Camry".
     */
    private function leadTitle(People $people): string
    {
        $who = VoiceCallerLookupService::isPlaceholderName($people->name)
            ? $this->phone
            : trim((string) $people->name);

        return implode(' — ', array_filter([
            $who,
            "{$this->directionLabel()} voice call",
            $this->interest,
        ]));
    }

    private function leadDescription(): string
    {
        return implode(' ', array_filter([
            "{$this->directionLabel()} voice call captured by the AI agent.",
            $this->interestNote(),
            $this->summary,
            $this->appointmentNote(),
        ]));
    }

    /**
     * Custom-field writes don't fire LeadObserver::updated, so the lead's SMS agent would
     * keep a session state that predates the call. A refresh failure must not turn a
     * saved capture into an error for the voice agent.
     */
    private function refreshAgentSessions(?Lead $lead): void
    {
        if ($lead === null || ! $lead->company->get(ConfigurationEnum::AI_ENABLE->value)) {
            return;
        }

        try {
            new UpdateLeadSessionsAction($lead)->execute();
        } catch (Throwable $e) {
            captureException($e);
        }
    }

    /**
     * What the next agent should know about this call, one item per line.
     */
    private function buildContextInfo(): string
    {
        // Summary first: it carries the runtime's "[voice <direction> <date>, <outcome>]"
        // header, which ContactHistoryService keeps as the line's header.
        return implode("\n", array_filter([
            $this->summary,
            $this->interestNote(),
            $this->appointmentNote(),
        ]));
    }

    private function directionLabel(): string
    {
        return $this->direction === 'outbound' ? 'Outbound' : 'Inbound';
    }

    private function interestNote(): ?string
    {
        return $this->interest !== null ? "Interest: {$this->interest}" : null;
    }

    private function appointmentNote(): ?string
    {
        if (! $this->wantsAppointment) {
            return null;
        }

        return $this->appointmentPreference !== null
            ? "Wants an appointment: {$this->appointmentPreference}."
            : 'Wants an appointment.';
    }
}
