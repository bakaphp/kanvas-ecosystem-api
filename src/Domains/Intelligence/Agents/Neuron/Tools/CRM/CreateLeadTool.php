<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Tools\Traits\Guild\CreatesLeadTrait;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Plain lead creation — a distinct lead per call with no session side effects, so an agent
 * can create leads for many people in one conversation (dedup is by the person's contact
 * inside createLead). Single-prospect agents use CaptureConversationLeadTool instead.
 */
#[AgentTool(name: 'Create Lead', category: 'crm')]
class CreateLeadTool extends Tool
{
    use CreatesLeadTrait;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
    ) {
        parent::__construct(
            name: 'create_lead',
            description: 'Register a new CRM lead for a person. Provide at minimum a name + email OR phone. '
                . 'Creates a distinct lead each call and returns its lead_id for subsequent lead-scoped tools.',
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
                description: 'Optional ID of an EXISTING organization to link the lead to. Leave 0 if unknown — '
                    . 'never guess an ID. Use organization_name instead when you only know the company name.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_name',
                type: PropertyType::STRING,
                description: 'Optional company / account the person belongs to (e.g. "Brooklinen"). Created if it '
                    . 'does not exist yet, and the person is added to it. Ignored when organization_id is given.',
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
        ?string $organization_name = null,
    ): array {
        return $this->createLead(
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
            organizationName: $organization_name,
        );
    }
}
