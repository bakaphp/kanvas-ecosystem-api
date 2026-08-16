<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Writes custom fields onto a person — the people-side counterpart of set_lead_custom_fields /
 * set_organization_custom_fields. Non-destructive: only the keys you pass are written, others are
 * left as-is. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Set Person Custom Fields', category: 'crm')]
class SetPersonCustomFieldsTool extends Tool
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'set_person_custom_fields',
            description: 'Store custom fields on a person (e.g. seniority, department, source, a computed score). '
                . 'Pass person_id and a map of field name → value; only those keys are written. Use get_person to '
                . 'read them back.',
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
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The id of the person to write to.',
                required: true,
            ),
            new ToolProperty(
                name: 'custom_fields',
                type: PropertyType::STRING,
                description: 'A JSON object mapping custom field name → value, passed as a string. '
                    . 'For example: {"seniority": "director", "source": "referral"}.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, array|string|null $custom_fields = null): array
    {
        $custom_fields = $this->decodeJsonObjectParam($custom_fields);

        if ($custom_fields === []) {
            return ['error' => 'Provide at least one custom field to set.'];
        }

        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        $written = [];
        foreach ($custom_fields as $name => $value) {
            $key = trim((string) $name);
            if ($key === '') {
                continue;
            }
            $person->set($key, $value);
            $written[$key] = $value;
        }

        return [
            'person_id' => $person->getId(),
            'set' => $written,
            'message' => count($written) . ' custom field(s) set.',
        ];
    }
}
