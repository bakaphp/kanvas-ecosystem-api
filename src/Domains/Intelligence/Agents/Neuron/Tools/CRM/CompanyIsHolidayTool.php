<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Companies\Services\CompanyHolidayService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Company Is Holiday', category: 'crm')]
class CompanyIsHolidayTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct(
    ) {
        parent::__construct(
            name: 'check_company_holiday',
            description: 'Check if today is a holiday, whether the company stays open on it (working day), and whether the company recognizes it for the AI to acknowledge.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }

        return new CompanyHolidayService($result->company)->check();
    }
}
