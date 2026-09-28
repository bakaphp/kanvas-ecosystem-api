<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;

class PeopleBouncesFeedService
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @param 'per_email'|'per_person' $granularity per_email = one row per bad address; per_person = one row per person (most severe)
     *
     * @return list<array{crm: string, person: string, company: string, email: string, status: string, bounced_at: ?string}>
     */
    public function rows(
        bool $includeSoftBounce = false,
        string $granularity = 'per_email',
        ?int $limit = null,
    ): array {
        $statuses = $this->badStatuses($includeSoftBounce);

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas(
                'contacts',
                fn (Builder $query): Builder => $query
                    ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
                    ->whereIn('validation_status', $statuses),
            )
            ->with([
                'contacts' => fn ($query): mixed => $query
                    ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
                    ->whereIn('validation_status', $statuses),
                'organizations' => fn ($query) => $query->select('organizations.id', 'name'),
            ])
            ->orderBy('id')
            ->get(['id', 'firstname', 'lastname']);

        $rows = [];

        foreach ($people as $person) {
            $badEmails = $person->contacts->values();
            if ($badEmails->isEmpty()) {
                continue;
            }

            if ($granularity === 'per_person') {
                $badEmails = collect([$this->mostSevere($badEmails)]);
            }

            $companyName = (string) ($person->organizations->first()?->name ?? $person->get('company') ?? '—');

            foreach ($badEmails as $contact) {
                $rows[] = [
                    'crm' => $this->company->name,
                    'person' => $person->getName(),
                    'company' => $companyName,
                    'email' => (string) $contact->value,
                    'status' => $this->statusValue($contact),
                    'bounced_at' => $contact->bounced_at?->toDateString(),
                ];

                if ($limit !== null && count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function badStatuses(bool $includeSoftBounce): array
    {
        $statuses = [
            ContactValidationStatusEnum::HARD_BOUNCE->value,
            ContactValidationStatusEnum::INVALID->value,
        ];

        if ($includeSoftBounce) {
            $statuses[] = ContactValidationStatusEnum::SOFT_BOUNCE->value;
        }

        return $statuses;
    }

    /**
     * @param Collection<int, Contact> $contacts non-empty (caller guards on isEmpty)
     */
    private function mostSevere(Collection $contacts): Contact
    {
        $rank = [
            ContactValidationStatusEnum::SOFT_BOUNCE->value => 1,
            ContactValidationStatusEnum::HARD_BOUNCE->value => 2,
            ContactValidationStatusEnum::INVALID->value => 3,
        ];

        /** @var Contact $contact */
        $contact = $contacts
            ->sortByDesc(fn (Contact $item): int => $rank[$this->statusValue($item)] ?? 0)
            ->first();

        return $contact;
    }

    private function statusValue(Contact $contact): string
    {
        // Rows are pre-filtered to a bad validation_status, so the cast enum is always present.
        return $contact->validation_status->value;
    }
}
