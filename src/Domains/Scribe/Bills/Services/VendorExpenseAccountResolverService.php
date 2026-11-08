<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\DataTransferObject\ExpenseAccountCoding;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\VendorCustomFieldEnum;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * Which expense account a bill from this vendor should be coded to, when the document itself doesn't say.
 *
 * The vendor's own default wins; otherwise the account its past bills were coded to most often. Only
 * bills a person or the agent actually coded count as history — drafts and voids are left out, because
 * an unreviewed inbox draft carries a placeholder and would teach the resolver its own guess.
 *
 * Null means "don't know", and the caller must treat it that way (leave the bill for review) rather than
 * fall back to a catch-all account.
 */
class VendorExpenseAccountResolverService
{
    private const int HISTORY_BILLS = 20;

    public function resolve(Organization $vendor): ?int
    {
        return $this->resolveWithSource($vendor)?->accountId;
    }

    public function resolveWithSource(Organization $vendor): ?ExpenseAccountCoding
    {
        $default = $this->vendorDefault($vendor);
        if ($default !== null) {
            return new ExpenseAccountCoding($default, ExpenseAccountCoding::VENDOR);
        }

        $history = $this->mostUsedInHistory($vendor);

        return $history !== null ? new ExpenseAccountCoding($history, ExpenseAccountCoding::HISTORY) : null;
    }

    private function vendorDefault(Organization $vendor): ?int
    {
        $accountNumber = trim((string) $vendor->get(VendorCustomFieldEnum::DEFAULT_EXPENSE_ACCOUNT->value, ''));

        if ($accountNumber === '') {
            return null;
        }

        $id = Account::query()
            ->where('apps_id', $vendor->apps_id)
            ->where('companies_id', $vendor->companies_id)
            ->where('account_number', $accountNumber)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function mostUsedInHistory(Organization $vendor): ?int
    {
        $billIds = DB::connection('accounting')->table('bills')
            ->where('apps_id', $vendor->apps_id)
            ->where('companies_id', $vendor->companies_id)
            ->where('vendor_organization_id', $vendor->getId())
            ->where('is_deleted', false)
            ->whereNotIn('document_status', [
                BillDocumentStatusEnum::DRAFT->value,
                BillDocumentStatusEnum::VOIDED->value,
            ])
            ->orderByDesc('id')
            ->limit(self::HISTORY_BILLS)
            ->pluck('id');

        if ($billIds->isEmpty()) {
            return null;
        }

        $id = DB::connection('accounting')->table('bill_lines')
            ->join(
                'accounts',
                'accounts.id',
                '=',
                'bill_lines.expense_account_id'
            )
            ->whereIn('bill_lines.bill_id', $billIds)
            ->where('accounts.is_active', true)
            ->where('accounts.is_deleted', false)
            ->groupBy('bill_lines.expense_account_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByDesc('bill_lines.expense_account_id')
            ->value('bill_lines.expense_account_id');

        return $id === null ? null : (int) $id;
    }
}
