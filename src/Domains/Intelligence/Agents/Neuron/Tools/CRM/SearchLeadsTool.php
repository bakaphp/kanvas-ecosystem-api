<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Free-text lead lookup so the agent can find leads itself instead of asking for a lead_id. Matches
 * the query against the lead title, the contact's name, and their email/phone contacts. Company-wide
 * by design — an internal-teammate capability, NOT for the customer-facing prospect surface (a
 * prospect must never be able to search other prospects; see Agents/CLAUDE.md audience rule).
 *
 * It also answers questions ABOUT the book, not only about one lead. An agent asked to audit which
 * leads were missing an email reported that it could not, and was right three times over: the query
 * term was required, so there was no way to ask about all leads; the result carried a contact NAME but
 * no address, so a returned row could not be judged; and nothing filtered on absence. Hence
 * `missing_contact`, an optional query, and a `total_matching` that ignores `limit` — "how many" is
 * the usual question, and paging 2,000 rows to count them is not an answer.
 */
#[AgentTool(name: 'Search Leads', category: 'crm')]
class SearchLeadsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'search_leads',
            description: 'Find leads by (partial) name, email, phone, or lead title — or audit the whole book. '
                . 'Use it whenever you need a lead_id you do not have ("find the lead for Ana", "which lead has '
                . 'this email"), and also to answer questions about contact data quality: pass missing_contact '
                . '("email", "phone", "either" or "both") to get every lead whose contact is missing that, with '
                . 'no query needed. The reply carries total_matching — the FULL count, not capped by limit — so '
                . 'you can answer "how many" in one call without paging. Returns lead_id, contact name, email, '
                . 'phone, owner, stage and status. Filter by status (open/closed/all) and by owner name/email. '
                . 'For MORE THAN ONE name — a spreadsheet column, a CSV, any list — use find_leads_bulk instead '
                . 'and pass every name in a single call; do not call this tool once per row.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'Text to search for: a contact name, email, phone number, or lead title. Omit it '
                    . 'to search across every lead — do that when auditing rather than looking someone up.',
                required: false,
            ),
            new ToolProperty(
                name: 'missing_contact',
                type: PropertyType::STRING,
                description: 'Return only leads whose contact is MISSING something: "email" (no email address '
                    . 'on file), "phone" (no phone number), "either" (missing at least one of the two), or '
                    . '"both" (unreachable — neither). A lead with no person attached counts as missing '
                    . 'everything. Omit to apply no such filter.',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Which leads to include: "open" (default), "closed", or "all".',
                required: false,
            ),
            new ToolProperty(
                name: 'owner',
                type: PropertyType::STRING,
                description: 'Filter to a lead owner (sales rep) by partial name or email. Omit for all owners.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max leads to LIST, most recently updated first. Defaults to 25, max 100. It does '
                    . 'not cap total_matching, so a count is right even when the list is truncated.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $query = null,
        ?string $missing_contact = null,
        ?string $status = null,
        ?string $owner = null,
        ?int $limit = null,
    ): array {
        $query = trim((string) $query);
        $missing = strtolower(trim((string) $missing_contact));

        if ($query === '' && $missing === '') {
            return [
                'count' => 0,
                'total_matching' => 0,
                'leads' => [],
                'error' => 'Give me either a query (a name, email, phone or lead title) or missing_contact '
                    . '("email", "phone", "either", "both") to audit by. Both empty would return the whole book '
                    . 'in no particular order.',
            ];
        }

        if ($missing !== '' && ! in_array($missing, ['email', 'phone', 'either', 'both'], true)) {
            return [
                'count' => 0,
                'total_matching' => 0,
                'leads' => [],
                'error' => sprintf(
                    'missing_contact must be "email", "phone", "either" or "both" — got "%s".',
                    $missing,
                ),
            ];
        }

        $limit = max(1, min(100, $limit ?? 25));

        $base = $this->baseQuery(
            $query,
            $missing,
            strtolower($status ?? 'open'),
            (string) $owner,
        );

        // Counted before the limit, because the question is usually "how many" and the list is the
        // evidence rather than the answer.
        $total = (clone $base)->count();

        $leads = $base
            ->with(['owner', 'people.contacts', 'stage'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $leads->count(),
            'total_matching' => $total,
            'truncated' => $total > $leads->count(),
            'leads' => $leads->map(fn (Lead $lead): array => $this->present($lead))->all(),
        ];
    }

    /**
     * @return Builder<Lead>
     */
    private function baseQuery(
        string $query,
        string $missing,
        string $status,
        string $owner,
    ): Builder {
        return Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($status === 'open', fn (Builder $q): Builder => $q->where(
                fn (Builder $s): Builder => $s->whereNull('status')->orWhere('status', '<', 2),
            ))
            ->when($status === 'closed', fn (Builder $q): Builder => $q->where('status', '>=', 2))
            ->when($query !== '', fn (Builder $q): Builder => $q->where(
                fn (Builder $inner): Builder => $inner
                    ->where('title', 'like', '%' . $query . '%')
                    ->orWhereHas(
                        'people',
                        fn (Builder $p): Builder => $p
                            ->where('firstname', 'like', '%' . $query . '%')
                            ->orWhere('lastname', 'like', '%' . $query . '%')
                            ->orWhereHas(
                                'contacts',
                                fn (Builder $c): Builder => $c->where('value', 'like', '%' . $query . '%'),
                            ),
                    ),
            ))
            ->when($missing !== '', fn (Builder $q): Builder => $this->applyMissingContact($q, $missing))
            ->when($owner !== '', fn (Builder $q): Builder => $this->applyOwner($q, $owner));
    }

    /**
     * A lead with no person attached is missing every kind of contact, so it belongs in all four
     * answers — leaving it out would understate exactly the leads nobody can reach.
     */
    private function applyMissingContact(Builder $query, string $missing): Builder
    {
        $emails = Contact::EMAIL_TYPES;
        $phones = Contact::PHONE_TYPES;

        return $query->where(function (Builder $q) use ($missing, $emails, $phones): void {
            $q->whereDoesntHave('people');

            if ($missing === 'email' || $missing === 'either') {
                $q->orWhere(fn (Builder $w): Builder => $this->whereLacksContact($w, $emails));
            }

            if ($missing === 'phone' || $missing === 'either') {
                $q->orWhere(fn (Builder $w): Builder => $this->whereLacksContact($w, $phones));
            }

            // Both conditions on ONE branch, so this matches a lead lacking an email AND a phone
            // rather than either of them.
            if ($missing === 'both') {
                $q->orWhere(function (Builder $w) use ($emails, $phones): void {
                    $this->whereLacksContact($w, $emails);
                    $this->whereLacksContact($w, $phones);
                });
            }
        });
    }

    /**
     * A blank `value` counts as absent: a contact row with an empty address is not a way to reach
     * anyone, and treating it as one is how an audit misses the records it exists to find.
     *
     * @param list<int> $typeIds
     */
    private function whereLacksContact(Builder $query, array $typeIds): Builder
    {
        return $query->whereDoesntHave(
            'people.contacts',
            fn (Builder $c): Builder => $c->whereIn('contacts_types_id', $typeIds)
                ->whereNotNull('value')
                ->where('value', '!=', ''),
        );
    }

    /**
     * `owner` is a Users relation on the `ecosystem` connection — resolve ids first instead of
     * whereHas(), which would try to join `users` on the `crm` connection.
     */
    private function applyOwner(Builder $query, string $owner): Builder
    {
        $ownerIds = Users::query()
            ->where(
                fn (Builder $o): Builder => $o->where('firstname', 'like', '%' . $owner . '%')
                    ->orWhere('lastname', 'like', '%' . $owner . '%')
                    ->orWhere('email', 'like', '%' . $owner . '%'),
            )
            ->pluck('id');

        return $query->whereIn('leads_owner_id', $ownerIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Lead $lead): array
    {
        $contacts = $lead->people?->contacts;
        $owner = $lead->owner;

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'contact' => $lead->people?->getName(),
            'email' => $this->firstContactValue($contacts, Contact::EMAIL_TYPES),
            'phone' => $this->firstContactValue($contacts, Contact::PHONE_TYPES),
            'owner' => $owner !== null
                ? trim((string) $owner->firstname . ' ' . (string) $owner->lastname)
                : null,
            'stage' => $lead->stage?->name,
            'is_open' => $lead->isOpen(),
            'last_updated' => $lead->updated_at?->toDateString(),
        ];
    }

    /**
     * Read off the eager-loaded contacts rather than People::getEmails(), which queries per person and
     * would put a query per row behind a 100-lead audit.
     *
     * @param iterable<Contact>|null $contacts
     * @param list<int> $typeIds
     */
    private function firstContactValue(?iterable $contacts, array $typeIds): ?string
    {
        foreach ($contacts ?? [] as $contact) {
            $value = trim((string) $contact->value);

            if ($value !== '' && in_array($contact->contacts_types_id, $typeIds, true)) {
                return $value;
            }
        }

        return null;
    }
}
