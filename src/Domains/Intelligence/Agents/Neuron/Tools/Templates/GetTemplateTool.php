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

    public function __construct()
    {
        parent::__construct(
            name: 'get_template',
            description: 'Get a single template by id, including its full HTML body — use this to inspect the current '
                . 'markup before fixing it with update_template. Returns an "owned" flag telling you whether you may '
                . 'edit or delete it.',
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
                name: 'template_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the template to fetch (from list_templates or create_template).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $template_id): array
    {
        return $this->getTemplate($this->app, $this->company, $this->user, $template_id);
    }
}
