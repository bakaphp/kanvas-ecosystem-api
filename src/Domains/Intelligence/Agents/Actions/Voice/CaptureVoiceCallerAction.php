<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Support\Str;
use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Spatie\LaravelData\DataCollection;
use Throwable;

use function Sentry\captureException;

/**
 * Voice data plane: capture a caller the agent is talking to.
 *
 * On the FIRST substantive call we record the person as a People (contact) — no
 * lead yet — so they're recognized next time. We PROMOTE to a Lead when they
 * qualify: either a repeat (a People for this number already existed) OR the
 * agent flags clear intent (`interested`). Details the agent learns (name,
 * interest) are backfilled onto the contact and, once promoted, the interest is
 * written to LEAD_CONTEXT_INFO so future calls read it back as context.
 *
 * Reuses the same People/Lead creation scaffolding as the ElevenLabs connector.
 * Best-effort: returns a structured result the LLM can speak; never throws.
 */
class CaptureVoiceCallerAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $phone,
        private readonly ?string $name = null,
        private readonly ?string $interest = null,
        private readonly bool $interested = false,
        private readonly ?string $direction = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        // Cross-app: the agent may live in a different app than the app-key the
        // voice runtime authenticated with. Bind the agent's app as the current
        // app so downstream app-scoped queries resolve against it — notably
        // CreateLeadAction re-resolving the People by id, whose getById scopes
        // fromApp(app(Apps::class)). Restore afterward so nothing leaks into the
        // rest of the request (same pattern as TenantAware*Searchable jobs).
        $previousApp = app(Apps::class);
        app()->instance(Apps::class, $this->agent->app);

        // Same reason, for RBAC: Role is a Bouncer model with a global scope that
        // filters by the ACTIVE scope, which is the runtime's app-key app. Lead
        // creation fires LeadObserver -> CreateChannelAction, which looks up the
        // "Admin" role for the agent's app; under the wrong scope that role is
        // hidden and firstOrFail throws. Point Bouncer at the agent's app scope.
        $previousScope = Bouncer::scope()->get();
        Bouncer::scope()->to(RolesEnums::getScope($this->agent->app));

        try {
            $company = $this->agent->companies_id > 0
                ? Companies::find($this->agent->companies_id)
                : null;
            if ($company === null) {
                return ['status' => 'error', 'message' => 'No company is set for this agent.'];
            }

            $app = $this->agent->app;
            // Records created here are attributed to the agent's dedicated AI user.
            $user = $company->getAiAgentUser() ?? $this->agent->user;

            // Existing People = they've been seen before (the "repeat" signal).
            $existing = $this->findPeople($app, $company);
            $isReturning = $existing !== null;
            $people = $existing ?? $this->createPeople($app, $company, $user);

            if ($this->name !== null && $this->name !== '') {
                $this->applyName($people);
            }
            if ($this->interest !== null && $this->interest !== '') {
                $people->set('voice_interest', $this->interest);
            }

            // Promote to a lead when there's no active lead yet. OUTBOUND (we
            // dialed them) requires clear intent — placing the call isn't itself
            // a qualifying signal. INBOUND keeps the "repeat OR intent" rule: a
            // second call counts even without stated intent.
            $lead = LeadsRepository::getPeopleActiveLead($people);
            $createdLead = false;
            $qualifies = $this->direction === 'outbound'
                ? $this->interested
                : ($isReturning || $this->interested);
            if ($lead === null && $qualifies) {
                $lead = $this->createLeadFromPeople($people, $user);
                $createdLead = true;
            }
            if ($lead !== null && $this->interest !== null && $this->interest !== '') {
                $lead->set(ConfigurationEnum::LEAD_CONTEXT_INFO->value, $this->interest);
            }

            return [
                'status' => 'success',
                'people_id' => $people->getId(),
                'lead_id' => $lead?->getId(),
                'created_lead' => $createdLead,
                'is_returning' => $isReturning,
                'message' => $createdLead
                    ? 'Saved the caller and created a lead.'
                    : 'Saved the caller details.',
            ];
        } catch (Throwable $e) {
            captureException($e);
            // Log the real reason — the spoken message below is deliberately
            // generic, so without this the actual failure is only in Sentry.
            logger()->error('captureVoiceLead failed', [
                'phone' => $this->phone,
                'agent_id' => $this->agent->getId(),
                'direction' => $this->direction,
                'exception' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ['status' => 'error', 'message' => "I couldn't save the caller details just now."];
        } finally {
            Bouncer::scope()->to($previousScope);
            app()->instance(Apps::class, $previousApp);
        }
    }

    private function findPeople(mixed $app, Companies $company): ?People
    {
        $people = PeoplesRepository::getByPhoneNumber(
            app: $app,
            company: $company,
            phoneNumbers: array_values(array_unique(array_filter([
                Str::sanitizePhoneNumber($this->phone),
                Str::normalizePhoneNumber($this->phone),
            ]))),
        )->get();

        if ($people->isEmpty()) {
            return null;
        }

        // Prefer someone with an active lead; else the first match.
        return $people->first(
            fn (People $p): bool => LeadsRepository::getPeopleActiveLead($p) !== null
        ) ?? $people->first();
    }

    private function createPeople(mixed $app, Companies $company, mixed $user): People
    {
        $contactData = [[
            'value' => $this->phone,
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'weight' => 100,
        ]];

        $dto = new PeopleDto(
            app: $app,
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

    /**
     * Backfill a real name, but never clobber one we already have — createPeople
     * stores the phone as firstname, so only overwrite a phone-like placeholder.
     */
    private function applyName(People $people): void
    {
        $current = trim((string) $people->firstname);
        $isPlaceholder = $current === '' || preg_match('/^[\d\s+()\-]+$/', $current) === 1;
        if (! $isPlaceholder) {
            return;
        }

        $parts = preg_split('/\s+/', trim((string) $this->name), 2) ?: [];
        $people->firstname = $parts[0] ?? '';
        $people->lastname = $parts[1] ?? '';
        $people->saveOrFail();
    }

    private function createLeadFromPeople(People $people, mixed $user): Lead
    {
        // Self-provision the "Warm" type if the company lacks it (it's a seeded
        // default, but older/incomplete companies may not have it) — same
        // create-if-missing pattern the Reynolds/Elead connectors use, so capture
        // never hard-fails on a missing lead type.
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
            new LeadSource($people->app, $people->company, $leadType->getId(), 'voice', true, 'voice')
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
            description: $this->leadDescription(),
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags(['voice-agent']);

        return $lead;
    }

    /**
     * A scannable lead title: who + how they came in + what they want, e.g.
     * "Rafael Lopez — Outbound voice call — interested in a 2024 Camry".
     * Falls back to the phone when we don't have a real name yet, and omits the
     * interest clause when none was captured.
     */
    private function leadTitle(People $people): string
    {
        $who = trim((string) $people->name);
        if ($who === '' || preg_match('/^[\d\s+()\-]+$/', $who) === 1) {
            $who = $this->phone;
        }

        $direction = $this->direction === 'outbound' ? 'Outbound' : 'Inbound';

        $parts = array_filter([
            $who,
            $direction . ' voice call',
            ($this->interest !== null && $this->interest !== '') ? trim($this->interest) : null,
        ]);

        return implode(' — ', $parts);
    }

    /**
     * Human-facing lead description for the CRM: how the lead came in plus the
     * interest the agent captured (when any).
     */
    private function leadDescription(): string
    {
        $direction = $this->direction === 'outbound' ? 'Outbound' : 'Inbound';
        $parts = ["{$direction} voice call captured by the AI agent."];
        if ($this->interest !== null && trim($this->interest) !== '') {
            $parts[] = 'Interest: ' . trim($this->interest);
        }

        return implode(' ', $parts);
    }
}
