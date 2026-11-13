<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Templates;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Templates\ManagesTemplatesTrait;
use Kanvas\Social\Tags\Models\Tag;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;

#[AgentTool(name: 'Tag Template', category: 'templates')]
class TagTemplateTool extends Tool
{
    use HasKanvasContext;
    use ManagesTemplatesTrait;

    protected string $name = 'tag_template';

    protected ?string $description = 'Add or remove tags on a template. Pass template_id and a list of tag names; set remove=true to '
        . 'detach them instead of attaching. Tags that do not exist yet are created. Returns the template\'s current tags.';

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'template_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the template to tag.',
                required: true,
            ),
            new ArrayProperty(
                name: 'tags',
                description: 'List of tag names to add or remove.',
                required: true,
                items: new ToolProperty(name: 'tag', type: PropertyType::STRING, description: 'A tag name.'),
            ),
            new ToolProperty(
                name: 'remove',
                type: PropertyType::BOOLEAN,
                description: 'When true, remove the given tags instead of adding them. Defaults to false.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    public function __invoke(int $template_id, array $tags, ?bool $remove = null): array
    {
        $tags = Tag::normalizeNames($tags);

        if ($tags === []) {
            return $this->invalidArgs('Provide at least one tag name.');
        }

        $owned = $this->resolveOwnedTemplate(
            $this->app,
            $this->company,
            $this->user,
            $template_id
        );
        if (isset($owned['error'])) {
            return $owned;
        }

        $template = $owned['template'];

        if ($remove === true) {
            $template->removeTags($tags);
        } else {
            $template->addTags(
                $tags,
                $this->app,
                $this->user,
                $this->company
            );
        }

        return $this->ok([
            'template_id' => $template->getId(),
            'tags' => $template->tags()->pluck('name')->all(),
            'message' => $remove === true ? 'Tags removed.' : 'Tags added.',
        ]);
    }
}
