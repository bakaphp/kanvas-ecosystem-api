<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\Guild\CreatesLeadTrait;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Create Lead')]
class CreateLeadTool extends Tool
{
    use CreatesLeadTrait;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly ?Session $session = null,
    ) {
        parent::__construct(
            name: 'create_lead',
            description: 'Register a new CRM lead for a prospect. Use this when no lead is in scope AND the conversation has revealed '
                . 'enough information to register a real prospect (at minimum: prospect name + email OR phone). '
                . 'DO NOT call this on a single hello message. Returns the lead_id that subsequent lead-scoped tools '
                . '(get_user_availability, create_calendar_event, get_lead_intent, etc.) require.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Lead title summarizing the prospect (e.g., "Demo request - Acme Corp").',
                required: true,
            ),
            new ToolProperty(
                name: 'firstname',
                type: PropertyType::STRING,
                description: 'First name of the contact, or the company name if no individual contact is known yet.',
                required: true,
            ),
            new ToolProperty(
                name: 'lastname',
                type: PropertyType::STRING,
                description: 'Last name of the contact. Omit for company-only leads.',
                required: false,
            ),
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'Contact email address.',
                required: false,
            ),
            new ToolProperty(
                name: 'phone',
                type: PropertyType::STRING,
                description: 'Contact phone number.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Full context / notes about this lead - what the prospect said, their pain points, urgency, source.',
                required: false,
            ),
            new ToolProperty(
                name: 'lead_type_id',
                type: PropertyType::INTEGER,
                description: 'Optional lead type ID. Leave 0 to use the tenant default.',
                required: false,
            ),
            new ToolProperty(
                name: 'lead_source_id',
                type: PropertyType::INTEGER,
                description: 'Optional lead source ID. Leave 0 to use the tenant default.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'Optional organization ID to link the lead to. Leave 0 if unknown.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $title,
        string $firstname,
        ?string $lastname = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $description = null,
        ?int $lead_type_id = null,
        ?int $lead_source_id = null,
        ?int $organization_id = null,
    ): array {
        // Refuse duplicate create_lead in the same session — return the
        // existing lead_id instead.
        if ($this->session?->entity_namespace === People::class && $this->session->entity_id) {
            $people = $this->session->entity();
            if ($people instanceof People) {
                $existingLead = LeadsRepository::getPeopleActiveLead($people);
                if ($existingLead !== null) {
                    return [
                        'lead_id' => $existingLead->getId(),
                        'duplicate_prevented' => true,
                        'note' => 'A lead already exists for this prospect. Use this lead_id '
                            . 'for subsequent lead-scoped tool calls.',
                    ];
                }
            }
        }

        $result = $this->createLead(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            title: $title,
            firstname: $firstname,
            lastname: $lastname,
            email: $email,
            phone: $phone,
            description: $description,
            leadTypeId: $lead_type_id ?? 0,
            leadSourceId: $lead_source_id ?? 0,
            organizationId: ($organization_id !== null && $organization_id > 0) ? $organization_id : null,
        );

        if (isset($result['lead_id']) && $this->session !== null) {
            $this->promoteSessionToPeople((int) $result['lead_id']);
            $result['session_promoted'] = true;
            $result['next_step'] = 'You may now call lead-scoped tools '
                . '(get_user_availability, create_calendar_event, get_lead_intent, etc.) '
                . 'with lead_id ' . $result['lead_id'] . ' in this same turn.';
        }

        return $result;
    }

    /**
     * Repoint the anonymous session at the prospect's People row. If a People
     * session already exists for this (people, agent), reuse its channel;
     * otherwise promote the current session in place. Pre-existing channel
     * messages are cross-linked to both People and Lead channels either way.
     */
    private function promoteSessionToPeople(int $leadId): void
    {
        $lead = Lead::find($leadId);
        $people = $lead?->people;

        if ($lead === null || $people === null) {
            return;
        }

        $peopleChannelService = new PeopleChannelService();
        $leadChannelService = new LeadChannelService();
        $peopleChannel = $peopleChannelService->findOrCreateForPeople(
            $people,
            $lead->app,
            $lead->company,
            $this->user
        );
        $leadChannelService->findOrCreateForLead(
            $lead,
            $lead->app,
            $lead->company,
            $this->user
        );

        $currentChannel = $this->session->channel;
        if ($currentChannel !== null) {
            // The ai-assist channel is keyed by (agent, user), so it accumulates
            // every anonymous chat this user ever had with this agent. Only
            // backfill messages tagged with THIS session's UUID — otherwise we
            // drag unrelated past conversations into the new People channel.
            foreach ($currentChannel->messages()->get() as $message) {
                $stored = $message->getMessage();
                $msgSessionId = $stored['thread_id'] ?? $stored['session_id'] ?? null;
                if ($msgSessionId !== $this->session->uuid) {
                    continue;
                }

                $peopleChannelService->attachMessageToPeopleChannel(
                    $message,
                    $people,
                    $lead->app,
                    $lead->company,
                    $this->user,
                );
                $leadChannelService->attachMessageToLeadChannel(
                    $message,
                    $lead,
                    $lead->app,
                    $lead->company,
                    $this->user,
                );
            }
        }

        $existingPeopleSession = Session::query()
            ->fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('agents_id', $this->session->agents_id)
            ->where('entity_namespace', People::class)
            ->where('entity_id', $people->getId())
            ->where('id', '!=', $this->session->id)
            ->first();

        $this->session->entity_namespace = People::class;
        $this->session->entity_id = $people->getId();
        $this->session->channel_id = $existingPeopleSession?->channel_id ?? $peopleChannel->getId();
        $this->session->saveQuietly();
    }
}
