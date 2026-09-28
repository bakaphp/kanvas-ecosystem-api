<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Templates;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\HasEntityContext;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Generate Template PDF', category: 'templates')]
class GenerateTemplatePdfTool extends Tool
{
    use HasEntityContext;
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    public function __construct()
    {
        parent::__construct(
            name: 'generate_template_pdf',
            description: 'Render a stored template to a PDF and attach it to the record currently in scope (the lead, '
                . 'order, message, etc. you are working on). Blade expressions in the template are rendered against '
                . 'that record. Pass the template name (from create_template) — not the id.',
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
                name: 'template_name',
                type: PropertyType::STRING,
                description: 'The name of the template to render (the name you gave it in create_template).',
                required: true,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'Optional name for the generated PDF file (".pdf" is added if missing). Defaults to the '
                    . 'template name.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $template_name, ?string $file_name = null): array
    {
        return $this->generateTemplatePdfForEntity(
            $this->app,
            $this->user,
            $this->entity,
            $template_name,
            $file_name
        );
    }
}
