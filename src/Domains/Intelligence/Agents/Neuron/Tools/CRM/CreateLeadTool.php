<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
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
            $this->promoteSessionToLead((int) $result['lead_id']);
            $result['session_promoted'] = true;
            $result['next_step'] = 'On the next turn, lead-scoped tools (get_user_availability, '
                . 'create_calendar_event, etc.) will be available for lead_id ' . $result['lead_id']
                . '. Respond conversationally now; do not try to call lead-scoped tools yet.';
        }

        return $result;
    }

    /**
     * Repoint the chat session at the newly-created lead so the next turn's
     * tools() resolution sees an entity instanceof Lead and unlocks the
     * lead-scoped toolset.
     */
    private function promoteSessionToLead(int $leadId): void
    {
        $this->session->entity_id = $leadId;
        $this->session->entity_namespace = Lead::class;
        $this->session->saveQuietly();
    }
}
