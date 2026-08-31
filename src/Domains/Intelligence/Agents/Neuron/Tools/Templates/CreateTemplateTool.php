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

#[AgentTool(name: 'Create Template', category: 'templates')]
class CreateTemplateTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    public function __construct()
    {
        parent::__construct(
            name: 'create_template',
            description: 'Create a reusable HTML template (Blade syntax allowed, e.g. {{ $entity->name }}) that can '
                . 'later be rendered to a PDF and attached to a record with generate_template_pdf. Give it a unique '
                . 'name you will reuse. The template belongs to you — only you can update or delete it afterwards. '
                . 'THIS is where the HTML goes: put the full markup in the `html` argument and never repeat it in '
                . 'your reply. Once it is stored, report what you built and name the template — that is the '
                . 'deliverable. If the name already exists, revise it with update_template instead of renaming.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'A unique name for the template (used later to render it, e.g. "quote-summary").',
                required: true,
            ),
            new ToolProperty(
                name: 'html',
                type: PropertyType::STRING,
                description: 'The HTML body of the template. Blade expressions like {{ $entity->firstname }} are '
                    . 'rendered against the record the PDF is attached to.',
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Optional subject line stored with the template.',
                required: false,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Optional title stored with the template.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        string $html,
        ?string $subject = null,
        ?string $title = null
    ): array {
        return $this->createTemplateRecord(
            $this->app,
            $this->company,
            $this->user,
            $name,
            $html,
            $subject,
            $title
        );
    }
}
