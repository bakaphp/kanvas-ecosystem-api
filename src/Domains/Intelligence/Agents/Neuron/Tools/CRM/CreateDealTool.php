<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Tools\Traits\Guild\CreatesDealTrait;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Creates a CRM deal from scratch. To promote an existing lead into a deal, use convert_lead_to_deal,
 * which copies the lead's contact over instead of re-typing it.
 */
#[AgentTool(name: 'Create Deal', category: 'crm')]
class CreateDealTool extends Tool implements HasRunKey
{
    use CreatesDealTrait;
    use TrackByInputs;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
    ) {
        parent::__construct(
            name: 'create_deal',
            description: 'Register a new CRM deal (pipeline opportunity). Provide at least a title. '
                . 'Link it to the source lead with leads_id when one exists, or to a person/organization. '
                . 'Leave pipeline/stage/owner unset to use the tenant defaults. Returns its deal_id for '
                . 'subsequent deal-scoped tools. To promote an existing lead into a deal, prefer convert_lead_to_deal.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Deal title summarizing the opportunity (e.g., "Acme Corp - 20 seat annual plan").',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Context / notes about the opportunity - scope, value, what the customer wants, next steps.',
                required: false,
            ),
            new ToolProperty(
                name: 'leads_id',
                type: PropertyType::INTEGER,
                description: 'ID of the lead this deal originated from. Omit or 0 if there is no source lead.',
                required: false,
            ),
            new ToolProperty(
                name: 'people_id',
                type: PropertyType::INTEGER,
                description: 'ID of the contact person for the deal. Omit or 0 if unknown.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'ID of the organization / company the deal is with. Omit or 0 if unknown.',
                required: false,
            ),
            new ToolProperty(
                name: 'owner_id',
                type: PropertyType::INTEGER,
                description: 'User ID of the sales rep who owns the deal. Omit or 0 to default to the current user.',
                required: false,
            ),
            new ToolProperty(
                name: 'pipeline_id',
                type: PropertyType::INTEGER,
                description: 'Pipeline ID to place the deal in. Omit or 0 to use the tenant default pipeline.',
                required: false,
            ),
            new ToolProperty(
                name: 'pipeline_stage_id',
                type: PropertyType::INTEGER,
                description: 'Pipeline stage ID to start the deal at. Omit or 0 to use the pipeline\'s first stage.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $title,
        ?string $description = null,
        ?int $leads_id = null,
        ?int $people_id = null,
        ?int $organization_id = null,
        ?int $owner_id = null,
        ?int $pipeline_id = null,
        ?int $pipeline_stage_id = null,
    ): array {
        return $this->createDeal(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            title: $title,
            description: $description,
            leadId: $leads_id,
            peopleId: $people_id,
            organizationId: $organization_id,
            ownerId: $owner_id,
            pipelineId: $pipeline_id,
            pipelineStageId: $pipeline_stage_id,
        );
    }
}
