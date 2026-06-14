<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * One amendment entry from `Invoice::amendmentHistory()`. Each row corresponds to one
 * AmendInvoiceAction call that actually changed something.
 *
 * `changes` keys are field names that mutated; each value is `{from, to}`. Operator UIs render
 * the diff side-by-side; aggregates / audits filter by `field_changed` keys (e.g. "show me every
 * due_date extension on overdue invoices").
 */
class InvoiceAmendment extends Data
{
    public function __construct(
        public readonly Carbon $amended_at,
        public readonly ?int $amended_by_users_id,
        public readonly string $reason,
        /** @var array<string, array{from: mixed, to: mixed}> */
        public readonly array $changes,
    ) {
    }

    /**
     * Field names changed by this amendment. Useful for filters like "show amendments that
     * touched due_date" without parsing the full diff.
     *
     * @return list<string>
     */
    public function fieldsChanged(): array
    {
        return array_keys($this->changes);
    }

    public function changedField(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }
}
