<?php

declare(strict_types=1);

namespace Tests\Scribe\Ledger;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * The GL second coding dimension: a posted JE line carries subaccount_id when supplied, and leaves
 * it null otherwise (subaccount is optional and doesn't affect balance).
 */
class JournalEntrySubaccountTest extends ScribeTestCase
{
    public function test_posted_line_persists_subaccount_id(): void
    {
        $subaccount = Subaccount::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'sub_code' => 'MKT-CA-000',
            'source' => 'acumatica',
        ]);

        $arId = $this->accountIdBySubType(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $revId = $this->accountIdBySubType(AccountSubTypeEnum::SERVICE_REVENUE);

        $entry = new PostJournalEntryAction(
            new JournalEntryData(
                app: $this->kanvasApp,
                company: $this->company,
                postedAt: Carbon::parse('2026-06-15'),
                sourceType: 'test',
                lines: new DataCollection(JournalEntryLineData::class, [
                    new JournalEntryLineData(
                        account_id: $arId,
                        debit_native: 100.0,
                        credit_native: 0.0,
                        debit_base: 100.0,
                        credit_base: 0.0,
                        currency: 'USD',
                        fx_rate_to_base: 1.0,
                        subaccount_id: (int) $subaccount->id,
                    ),
                    new JournalEntryLineData(
                        account_id: $revId,
                        debit_native: 0.0,
                        credit_native: 100.0,
                        debit_base: 0.0,
                        credit_base: 100.0,
                        currency: 'USD',
                        fx_rate_to_base: 1.0,
                    ),
                ]),
            ),
            static::$cachedUser,
        )->execute();

        $entry->load('lines');

        $arLine = $entry->lines->firstWhere('account_id', $arId);
        $revLine = $entry->lines->firstWhere('account_id', $revId);

        $this->assertSame((int) $subaccount->id, (int) $arLine->subaccount_id, 'AR line carries the subaccount.');
        $this->assertNull($revLine->subaccount_id, 'Revenue line has no subaccount.');
    }
}
