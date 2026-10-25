<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Templates;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Get Template', category: 'templates')]
class GetTemplateTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    protected string $name = 'get_template';

    protected ?string $description = 'Get a single template by id, including its full HTML body — use this to inspect the current '
        . 'markup before fixing it with update_template. Returns an "owned" flag: when it is false you '
        . 'CANNOT edit or delete that template. When "editable_here" is present and false, the template is also '
        . 'read-only in this company even if owned. Check these flags before promising a change.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'template_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the template to fetch (from list_templates or create_template).',
                required: true,
            ),
            ...$this->tenantCompanyProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $template_id, ?int $tenant_company_id = null): array
    {
        if (($refusal = $this->narrowReadScope($tenant_company_id)) !== null) {
            return $refusal;
        }

        return $this->getTemplate(
            $this->app,
            $this->readCompanyScope(),
            $this->user,
            $template_id
        );
    }
}
