<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Services\PeopleDuplicateQueueService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\PresentsPeopleDuplicateGroups;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lists every person in the company, so it stays off customer-facing agents.
 */
#[AgentTool(name: 'Find People Duplicates', category: 'crm')]
class FindPeopleDuplicatesTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use PresentsPeopleDuplicateGroups;
    use ReportsToolOutcome;

    protected string $name = 'find_people_duplicates';

    protected ?string $description = 'Report of possible duplicate people (contacts) in the CRM, grouped. Each group has a reason '
        . '(external_id_conflict, exact_name, lastname_match, firstname_match, email_match), the shared_value, a '
        . 'recommendation (safe_to_merge or review_first) and every member with role (keep or merge), name, emails, '
        . 'phones, organizations, created_at and differs_from_keep. Use for "duplicate people", '
        . '"duplicated contacts", "people to merge". Read-only: it never merges anything. Set refresh=true only when the '
        . 'user asks for a new scan; otherwise it reads the review queue.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'max_groups',
                type: PropertyType::INTEGER,
                description: 'Maximum groups to return. Default 25, max 100.',
                required: false,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Only groups with this reason: external_id_conflict, exact_name, lastname_match, firstname_match or email_match.',
                required: false,
            ),
            new ToolProperty(
                name: 'refresh',
                type: PropertyType::BOOLEAN,
                description: 'Scan the whole company for new duplicates before reading. Slower; only when the user asks for a fresh scan.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $max_groups = null, ?string $reason = null, ?bool $refresh = null): array
    {
        $reason = Str::trimToNull($reason);

        if ($reason !== null && ! in_array($reason, self::DUPLICATE_REASONS, true)) {
            return $this->invalidArgs('Unknown reason "' . $reason . '". Use one of: ' . implode(', ', self::DUPLICATE_REASONS) . '.');
        }

        $queue = new PeopleDuplicateQueueService($this->app, $this->company);

        if ($refresh === true) {
            $queue->detect();
        }

        $groups = $queue->pending(max(1, min($max_groups ?? 25, 100)), $reason);

        if ($groups === []) {
            return $this->noop(
                ['count' => 0, 'groups' => []],
                'No pending duplicate groups. Tell the user; do not call this tool again with the same arguments.'
            );
        }

        $presented = $this->presentDuplicateGroups($this->app, $this->company, $groups);

        return $this->ok(
            ['count' => count($presented), 'groups' => $presented],
            'Present each group with its shared_value, its recommendation and every member\'s differs_from_keep, '
            . 'safe_to_merge groups first. Merging is irreversible: only merge a group after the user confirms it, '
            . 'keeping the member with role "keep" as the survivor unless the user picks another.'
        );
    }
}
