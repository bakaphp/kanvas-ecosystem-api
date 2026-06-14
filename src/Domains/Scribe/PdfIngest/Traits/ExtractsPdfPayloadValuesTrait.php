<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Traits;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Throwable;

/**
 * Shared payload-parsing helpers for the `Propose*FromPdf` actions (Expense, Bill, future
 * vendor-credit, etc.). Centralizes type-coercion, date parsing, and the "what expense account
 * should we default to?" lookup — all of which were copy-pasted byte-for-byte across the Propose
 * actions before this trait existed.
 *
 * Consumers MUST expose:
 *   - protected/public readonly `$app` :: AppInterface
 *   - protected/public readonly `$company` :: CompanyInterface
 *
 * `resolveDefaultExpenseAccountId` reads these to look up the default Travel & Meals account (or
 * any active EXPENSE-typed account as a last resort) for the current tenant.
 */
trait ExtractsPdfPayloadValuesTrait
{
    /**
     * Coerce arbitrary mixed input (LLM JSON values are unpredictable) to a float, or null when
     * the input is missing/non-numeric. Returns `null` for empty strings, NOT `0.0` — callers
     * differentiate "no value" from "value 0" downstream.
     */
    protected function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Carbon parsing that never throws — bad input becomes null. Use when extracted dates may be
     * "2026-06-15", "06/15/2026", or garbage from a poorly-extracted PDF.
     */
    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * String trim with a `$default` fallback for missing / empty / non-string input.
     */
    protected function trimOrDefault(mixed $value, ?string $default): ?string
    {
        if (! is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    /**
     * Default expense account for an unclassified Propose-flow line. Tries Travel & Meals first;
     * any active EXPENSE-typed account as a last resort. Returns null when the tenant has no
     * usable expense account (operator UI will prompt them to pick one before approval).
     *
     * Reads `$this->app` + `$this->company` — host class must declare them.
     */
    protected function resolveDefaultExpenseAccountId(): ?int
    {
        $row = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::TRAVEL_AND_MEALS->value)
            ->where('is_deleted', false)
            ->value('id');

        if ($row !== null) {
            return (int) $row;
        }

        $row = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_type', 'expense')
            ->where('is_deleted', false)
            ->value('id');

        return $row === null ? null : (int) $row;
    }
}
