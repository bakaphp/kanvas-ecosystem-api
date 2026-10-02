<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Actions\AssignDealerTagToLeadAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Social\Tags\Models\Tag;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;

/**
 * Adds or removes tags on a lead — the lead-side counterpart of tag_person.
 *
 * The company's rooftop tags (`lead-dealer-tags`) are off limits: AssignDealerTagToLeadAction owns
 * them and records which trigger set one, so a tag swapped by hand would be put back, or kept
 * against the owner rule, on the lead's next update.
 */
#[AgentTool(name: 'Tag Lead', category: 'crm')]
class TagLeadTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'tag_lead',
            description: 'Add or remove tags on a lead. Pass lead_id and a list of tag names; set remove=true to '
                . 'detach them instead of attaching. Tags that do not exist yet are created. Dealer/rooftop tags '
                . 'are assigned automatically and cannot be changed here. Returns the lead\'s current tags.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The id of the lead.',
                required: true,
            ),
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
    public function __invoke(int $lead_id, array $tags, ?bool $remove = null): array
    {
        $tags = Tag::normalizeNames($tags);

        if ($tags === []) {
            return ['error' => 'Provide at least one tag name.'];
        }

        $lead = $this->resolveLeadOrError($lead_id);

        if (is_array($lead)) {
            return $lead;
        }

        $dealerTags = array_map(mb_strtolower(...), AssignDealerTagToLeadAction::dealerTags($lead->company));
        $refused = array_values(array_filter($tags, fn (string $tag) => in_array(mb_strtolower($tag), $dealerTags, true)));
        $tags = array_values(array_diff($tags, $refused));

        if ($tags !== []) {
            if ($remove === true) {
                $lead->removeTags($tags);
            } else {
                $lead->addTags(
                    $tags,
                    $this->app,
                    $this->user,
                    $this->company
                );
            }
        }

        return array_filter([
            'lead_id' => $lead->getId(),
            'tags' => $lead->tags()->pluck('name')->all(),
            'message' => match (true) {
                $tags === [] => 'Nothing changed: dealer tags are assigned automatically.',
                $remove === true => 'Tags removed.',
                default => 'Tags added.',
            },
            'refused_dealer_tags' => $refused ?: null,
        ], fn ($value) => $value !== null);
    }
}
