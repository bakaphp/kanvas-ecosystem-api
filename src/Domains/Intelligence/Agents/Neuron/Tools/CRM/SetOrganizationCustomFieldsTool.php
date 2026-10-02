<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\SetsOrganizationCustomFieldsTrait;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Set Organization Custom Fields', category: 'crm')]
class SetOrganizationCustomFieldsTool extends Tool implements HasRunKey
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use SetsOrganizationCustomFieldsTrait;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_organization_custom_fields',
            description: 'Store custom fields on a customer organization (e.g. industry, sector, employee count, a '
                . 'classification or score). Pass organization_id and a map of field name → value; only those keys '
                . 'are written, the rest are left as-is. Use get_organization to read them back.',
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
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The id of the organization to write to.',
                required: true,
            ),
            new ToolProperty(
                name: 'custom_fields',
                type: PropertyType::STRING,
                description: 'A JSON object mapping custom field name → value, passed as a string. '
                    . 'For example: {"industry": "Banking", "employees": 250}.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $organization_id, array|string|null $custom_fields = null): array
    {
        $result = $this->setOrganizationCustomFields(
            app: $this->app,
            company: $this->company,
            organizationId: $organization_id,
            fields: $this->decodeJsonObjectParam($custom_fields),
        );

        return isset($result['error']) ? $this->invalidArgs($result['error']) : $this->ok($result);
    }
}
