<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleDuplicateQueueService;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;
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
    use ExtractsPersonContacts;
    use HasKanvasContext;
    use ReportsToolOutcome;

    private const array REASONS = ['external_id_conflict', 'exact_name', 'lastname_match', 'firstname_match', 'email_match'];

    protected string $name = 'find_people_duplicates';

    protected ?string $description = 'Report of possible duplicate people (contacts) in the CRM, grouped. Each group has a reason '
        . '(external_id_conflict, exact_name, lastname_match, firstname_match, email_match), a canonical_id (the oldest '
        . 'record) and every member with name, emails, phones, organizations and created_at. Use for "duplicate people", '
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

        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            return $this->invalidArgs('Unknown reason "' . $reason . '". Use one of: ' . implode(', ', self::REASONS) . '.');
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

        $people = $this->loadMembers($groups);

        return $this->ok(
            [
                'count' => count($groups),
                'groups' => array_map(
                    fn (DuplicateReviewGroup $group): array => [
                        'group_id' => $group->getId(),
                        'reason' => $group->reason,
                        'canonical_id' => (int) $group->canonical_id,
                        'members' => array_values(array_filter(array_map(
                            fn ($id): ?array => $this->presentMember($people->get((int) $id)),
                            $group->member_ids,
                        ))),
                    ],
                    $groups,
                ),
            ],
            'Present the groups as a table by reason. Merging is irreversible: only merge a group after the user '
            . 'confirms it, keeping canonical_id as the survivor unless the user picks another.'
        );
    }

    /**
     * @param list<DuplicateReviewGroup> $groups
     * @return Collection<int, People>
     */
    private function loadMembers(array $groups): Collection
    {
        $ids = array_unique(array_merge(...array_map(fn (DuplicateReviewGroup $group): array => $group->member_ids, $groups)));

        return People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->with([
                'contacts',
                'organizations' => fn ($query) => $query->select('organizations.id', 'name'),
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentMember(?People $person): ?array
    {
        if ($person === null) {
            return null;
        }

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'emails' => $this->allEmails($person),
            'phones' => $this->allPhones($person),
            'organizations' => $person->organizations->pluck('name')->values()->all(),
            'created_at' => $person->created_at?->toDateTimeString(),
        ];
    }
}
