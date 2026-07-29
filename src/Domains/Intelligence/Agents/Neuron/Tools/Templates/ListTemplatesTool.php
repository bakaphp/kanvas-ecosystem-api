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

#[AgentTool(name: 'List Templates', category: 'templates')]
class ListTemplatesTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    public function __construct()
    {
        parent::__construct(
            name: 'list_templates',
            description: 'List the templates available to render in this company (including shared/global ones). Each '
                . 'result shows its id, name, and an "owned" flag — you can only update or delete the ones you own. '
                . 'Use this to discover templates before rendering, updating, or deleting.',
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
                name: 'search',
                type: PropertyType::STRING,
                description: 'Optional text to filter templates by name.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $search = null): array
    {
        return $this->listTemplates($this->app, $this->company, $this->user, $search);
    }
}
