<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Rules\Models\Rule;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The vocabulary `create_message` expects, and the reason a typo stops being silent.
 *
 * A rule watches one exact `message_type_id`. An agent writing `news-artcle` instead of `news-article`
 * gets a brand-new type, a saved message, and a successful tool result — while the rule that was
 * supposed to publish it never fires. Nothing errors, and the outcome is indistinguishable from the
 * agent deciding there was nothing to write. Picking from this list instead of typing a string is what
 * prevents that.
 *
 * `automated` is the part worth reading: it says a workflow is actually watching that type.
 */
#[AgentTool(name: 'List Message Types', category: 'social')]
class ListMessageTypesTool extends Tool
{
    use HasKanvasContext;

    private const int DEFAULT_LIMIT = 50;

    public function __construct()
    {
        parent::__construct(
            name: 'list_message_types',
            description: 'Lists the message types this app has, with how many messages use each and whether '
                . 'a workflow is watching it. Call this before writing a record with create_message and use a '
                . 'verb from here verbatim — a verb that is not on this list silently creates a new type that '
                . 'nothing is configured to act on, so the record is saved and nothing happens.',
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
                description: 'Optional term to filter by verb or name, e.g. "article", "note".',
                required: false,
            ),
            new ToolProperty(
                name: 'automated_only',
                type: PropertyType::BOOLEAN,
                description: 'Optional. True returns only the types a workflow is watching — the ones where '
                    . 'writing a record actually sets something running.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $search = null, ?bool $automated_only = null): array
    {
        if (! isset($this->app)) {
            return [
                'status' => 'error',
                'message' => 'This tool has no app context, so it cannot list message types.',
            ];
        }

        $search = trim((string) $search);

        $query = MessageType::query()
            ->where('apps_id', $this->app->getId())
            ->orderBy('verb');

        if ($search !== '') {
            $needle = '%' . $search . '%';
            $query->where(function ($query) use ($needle): void {
                $query->where('verb', 'like', $needle)
                    ->orWhere('name', 'like', $needle);
            });
        }

        $watched = $this->typeIdsWatchedByAWorkflow();

        // Narrowed in the query, not after: filtering a page of 50 would hide automated types that
        // simply sort late, and answer "none" when the truth is "not on this page".
        if ((bool) $automated_only) {
            $query->whereIn('id', $watched ?: [0]);
        }

        $types = $query->limit(self::DEFAULT_LIMIT)->get()
            ->map(fn (MessageType $type): array => [
                'verb' => $type->verb,
                'name' => $type->name,
                'messages' => $type->messages()->count(),
                'automated' => in_array($type->getId(), $watched, true),
            ])
            ->all();

        return [
            'status' => 'success',
            'types' => $types,
            'note' => 'Use a verb from this list exactly as written. "automated" means a workflow is '
                . 'watching that type, so writing a record of it sets something running; the others are '
                . 'just labels.',
        ];
    }

    /**
     * Which types a rule is configured to act on, read out of the rules' own params rather than
     * pattern-matched out of the stored JSON — an id written as 42 and as "42" must both count.
     *
     * @return list<int>
     */
    private function typeIdsWatchedByAWorkflow(): array
    {
        $rules = Rule::query()
            ->fromApp($this->app)
            ->where('is_deleted', 0)
            ->when(isset($this->company), fn ($query) => $query->fromCompany($this->company))
            ->get(['params']);

        $ids = [];

        foreach ($rules as $rule) {
            $params = $rule->params;

            if (is_array($params) && isset($params['message_type_id'])) {
                $ids[] = (int) $params['message_type_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
