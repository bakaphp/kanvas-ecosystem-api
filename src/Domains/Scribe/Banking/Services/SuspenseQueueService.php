<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;

/**
 * Cash that is on the books with an unexplained other side: posted to Suspense, matched to nothing,
 * not reclassified yet. What the agent works after every statement.
 */
class SuspenseQueueService
{
    public function parked(AppInterface $app, CompanyInterface $company): Builder
    {
        return BankTransaction::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->whereNotNull('journal_entry_id')
            ->where('match_status', BankTransactionMatchStatusEnum::UNMATCHED->value)
            ->whereIn('category', [
                BankTransactionCategoryEnum::UNKNOWN->value,
                BankTransactionCategoryEnum::TRANSFER->value,
            ])
            ->whereNull('suspense_reclassified_journal_entry_id');
    }
}
