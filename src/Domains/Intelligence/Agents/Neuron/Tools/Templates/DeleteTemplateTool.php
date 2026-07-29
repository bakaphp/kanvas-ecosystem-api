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

#[AgentTool(name: 'Delete Template', category: 'templates')]
class DeleteTemplateTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_template',
            description: 'Delete a template you previously created. Only templates you created can be deleted — you '
                . 'cannot remove system templates or ones created by others. Pass the template_id returned by '
                . 'create_template.',
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
                description: 'The ID of the template to delete (returned by create_template).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $template_id): array
    {
        return $this->deleteTemplateRecord($this->app, $this->company, $this->user, $template_id);
    }
}
