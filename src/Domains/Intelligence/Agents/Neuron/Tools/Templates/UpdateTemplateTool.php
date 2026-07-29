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

#[AgentTool(name: 'Update Template', category: 'templates')]
class UpdateTemplateTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    public function __construct()
    {
        parent::__construct(
            name: 'update_template',
            description: 'Change the HTML (or subject/title) of a template you previously created — use this when a '
                . 'rendered PDF does not look right and you want to fix the markup. Only templates you created can be '
                . 'updated. Pass the template_id returned by create_template.',
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
                description: 'The ID of the template to update (returned by create_template).',
                required: true,
            ),
            new ToolProperty(
                name: 'html',
                type: PropertyType::STRING,
                description: 'The new HTML body. Omit to leave the body unchanged.',
                required: false,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'New subject line. Omit to leave unchanged.',
                required: false,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'New title. Omit to leave unchanged.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $template_id,
        ?string $html = null,
        ?string $subject = null,
        ?string $title = null
    ): array {
        return $this->updateTemplateRecord(
            $this->app,
            $this->company,
            $this->user,
            $template_id,
            $html,
            $subject,
            $title
        );
    }
}
