<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

/**
 * Adds or removes tags on a person — the people-side counterpart of add_lead_tags. New tags are
 * created automatically. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Tag Person', category: 'crm')]
class TagPersonTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'tag_person',
            description: 'Add or remove tags on a person. Pass person_id and a list of tag names; set remove=true to '
                . 'detach them instead of attaching. Tags that do not exist yet are created. Returns the person\'s '
                . 'current tags.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ArrayProperty(
                name: 'tags',
                description: 'List of tag names to add (or remove when remove=true).',
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
    public function __invoke(int $person_id, array $tags, ?bool $remove = null): array
    {
        $tags = array_values(array_filter(array_map(
            fn (string $tag): string => trim($tag),
            $tags,
        ), fn (string $tag): bool => $tag !== ''));

        if ($tags === []) {
            return ['error' => 'Provide at least one tag name.'];
        }

        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        if ($remove === true) {
            $person->removeTags($tags);
        } else {
            $person->addTags($tags, $this->app, $this->user, $this->company);
        }

        return [
            'person_id' => $person->getId(),
            'tags' => $person->tags()->pluck('name')->all(),
            'message' => $remove === true ? 'Tags removed.' : 'Tags added.',
        ];
    }
}
