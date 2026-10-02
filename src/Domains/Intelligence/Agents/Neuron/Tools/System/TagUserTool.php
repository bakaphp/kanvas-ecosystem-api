<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Tags land on the user's membership in this company, not on the global user, so they are only
 * visible to this company.
 */
#[AgentTool(name: 'Tag User', category: 'ecosystem')]
class TagUserTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'tag_user',
            description: 'Add or remove tags on a teammate (a user of this company, not a CRM contact). Pass user_id and a '
                . 'list of tag names; set remove=true to detach them instead of attaching. Tags that do not exist yet are '
                . 'created. Returns the user\'s current tags in this company.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'user_id', type: PropertyType::INTEGER, description: 'The id of the user.', required: true),
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
    public function __invoke(int $user_id, array $tags, ?bool $remove = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->denied('This tool is not available without a company context.');
        }

        if (($refusal = $this->requireAdminOrError()) !== null) {
            return $this->denied($refusal['message']);
        }

        $tags = Tag::normalizeNames($tags);

        if ($tags === []) {
            return $this->invalidArgs('Provide at least one tag name.');
        }

        try {
            /** @var Users $user */
            $user = Users::getById($user_id);
            $membership = UsersRepository::belongsToThisApp($user, $this->app, $this->company);
        } catch (Throwable) {
            return $this->notFound(
                ['success' => false, 'error' => sprintf('No user #%d found in this company.', $user_id)],
                'Do not retry with the same id; confirm which teammate was meant first.'
            );
        }

        if ($remove === true) {
            $membership->removeTags($tags);
        } else {
            $membership->addTags(
                $tags,
                $this->app,
                $this->contextUser(),
                $this->company
            );
        }

        return $this->ok([
            'user_id' => $user->getId(),
            'tags' => $membership->tags()->pluck('name')->all(),
            'message' => $remove === true ? 'Tags removed.' : 'Tags added.',
        ]);
    }
}
