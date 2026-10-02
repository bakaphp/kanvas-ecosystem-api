<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Social\Tags\Models\Tag;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Tag Organization', category: 'crm')]
class TagOrganizationTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'tag_organization',
            description: 'Add or remove tags on a customer organization. Pass organization_id and a list of tag names; '
                . 'set remove=true to detach them instead of attaching. Tags that do not exist yet are created. '
                . 'Returns the organization\'s current tags.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'organization_id', type: PropertyType::INTEGER, description: 'The id of the organization.', required: true),
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
    public function __invoke(int $organization_id, array $tags, ?bool $remove = null): array
    {
        $tags = Tag::normalizeNames($tags);

        if ($tags === []) {
            return $this->invalidArgs('Provide at least one tag name.');
        }

        $result = $this->resolveOrganization($organization_id, null);
        if (is_array($result)) {
            return $result;
        }
        $organization = $result;

        if ($remove === true) {
            $organization->removeTags($tags);
        } else {
            $organization->addTags($tags, $this->app, $this->user, $this->company);
        }

        return $this->ok([
            'organization_id' => $organization->getId(),
            'tags' => $organization->tags()->pluck('name')->all(),
            'message' => $remove === true ? 'Tags removed.' : 'Tags added.',
        ]);
    }
}
