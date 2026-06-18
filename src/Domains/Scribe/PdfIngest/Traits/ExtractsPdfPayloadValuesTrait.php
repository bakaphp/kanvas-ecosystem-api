<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Traits;

use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * Scribe-specific PDF-ingest helpers. Generic `mixed → primitive` coercion lives in Baka's
 * `ScalarCoercionTrait` (`floatOrNull`, `intOrNull`, `stringOrNull`, `parseIso`, …) — composed
 * here so consumers get the full helper surface in one trait.
 *
 * `resolveDefaultExpenseAccountId` is Scribe-specific: it picks the tenant's default Travel & Meals
 * account (or any active EXPENSE-typed account as a last resort) for unclassified Propose-flow
 * lines. Consumers must expose `$this->app` (AppInterface) + `$this->company` (CompanyInterface).
 */
trait ExtractsPdfPayloadValuesTrait
{
    use ScalarCoercionTrait;

    /**
     * String trim with a `$default` fallback for missing / empty / non-string input. Differs from
     * `ScalarCoercionTrait::stringOrNull` only in that it trims whitespace first — LLM-extracted
     * fields routinely arrive with leading/trailing spaces that must not survive into snapshots.
     */
    protected function trimOrDefault(mixed $value, ?string $default): ?string
    {
        if (! is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

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
