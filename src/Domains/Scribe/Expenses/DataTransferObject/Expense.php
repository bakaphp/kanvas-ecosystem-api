<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<ExpenseLine> $lines
 */
class Expense extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        /** @var DataCollection<ExpenseLine> */
        public readonly DataCollection $lines,
        public readonly Carbon $expense_date,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly ExpensePaidByEnum $paid_by,
        public readonly ?PayeeInterface $vendor = null,
        /**
         * Where the money was spent, when there is no Organization to point `vendor` at — a
         * restaurant or taxi an employee expensed. Written straight through on create so a caller
         * does not have to patch the row afterwards.
         */
        public readonly ?string $vendor_display_name = null,
        public readonly ?int $paid_by_users_id = null,
        public readonly ?int $payment_method_id = null,
        public readonly ?int $bank_account_id = null,
        public readonly ?string $expense_number = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?array $tax_metadata = null,
        public readonly ?array $metadata = null,
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
    ) {
    }
}
