<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListSuspenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ReclassifySuspenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SettleFromSuspenseTool;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Spatie\LaravelData\DataCollection;

final class SuspenseToolsTest extends BankStatementTestCase
{
    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount();
    }

    public function testListSuspenseShowsOnlyUnexplainedCash(): void
    {
        $parked = $this->parkedMovement(BankTransactionDirectionEnum::DEBIT, 80.00, 'Mystery debit');
        $this->parkedMovement(BankTransactionDirectionEnum::CREDIT, 500.00, 'Mystery deposit');

        $all = $this->tool(new ListSuspenseTool())();
        $debits = $this->tool(new ListSuspenseTool())(direction: 'debit');

        $this->assertSame(2, $all['count']);
        $this->assertSame(1, $debits['count']);
        $this->assertSame($parked->getId(), $debits['movements'][0]['bank_transaction_id']);
        $this->assertSame(80.00, $debits['movements'][0]['amount']);
    }

    public function testReclassifyDrainsSuspenseToTheNamedAccount(): void
    {
        $parked = $this->parkedMovement(BankTransactionDirectionEnum::DEBIT, 80.00, 'Comision');

        $result = $this->tool(new ReclassifySuspenseTool())($parked->getId(), 'Bank Fees');

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
        $this->assertSame(80.00, $this->netMovementOn(AccountSubTypeEnum::BANK_FEES));
        $this->assertSame(0, $this->tool(new ListSuspenseTool())()['count'], 'A reclassified movement leaves the queue.');

        $again = $this->tool(new ReclassifySuspenseTool())($parked->getId(), 'Bank Fees');
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE), 'Reclassifying twice posts nothing more.');
        $this->assertTrue($again['success']);
    }

    public function testReclassifyRefusesAnAccountItCannotPinDown(): void
    {
        $parked = $this->parkedMovement(BankTransactionDirectionEnum::DEBIT, 80.00, 'Comision');

        $result = $this->tool(new ReclassifySuspenseTool())($parked->getId(), 'Something Vague');

        $this->assertFalse($result['success']);
        $this->assertSame('account_not_found', $result['reason']);
        $this->assertSame(80.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testSettleFromSuspensePaysTheLateBillWithoutCreditingCashTwice(): void
    {
        $parked = $this->parkedMovement(BankTransactionDirectionEnum::DEBIT, 450.00, 'Transferencia');
        $vendor = $this->seedTestOrganization('Edesur');
        $bill = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Electricity',
                        quantity: 1,
                        unit_price_native: 450.00,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse('2026-06-30'),
            ),
            user: static::$cachedUser,
        )->execute();

        $result = $this->tool(new SettleFromSuspenseTool())($parked->getId(), $bill->getId());

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->refresh()->document_status);
        $this->assertSame(-450.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING), 'Cash left once.');
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testToolsNeverReachAnotherCompanysRecords(): void
    {
        $foreign = $this->parkedMovement(BankTransactionDirectionEnum::DEBIT, 80.00, 'Comision');
        BankTransaction::query()->where('id', $foreign->getId())->update(['companies_id' => 0]);

        $result = $this->tool(new ReclassifySuspenseTool())($foreign->getId(), 'Bank Fees');

        $this->assertSame('bank_transaction_not_found', $result['reason']);
    }

    private function parkedMovement(BankTransactionDirectionEnum $direction, float $amount, string $memo): BankTransaction
    {
        $postedAt = Carbon::parse('2026-06-10');

        $transaction = new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->kanvasApp,
                company: $this->company,
                bankAccount: $this->account,
                postedAt: $postedAt,
                transactionDate: $postedAt,
                direction: $direction,
                amountNative: $amount,
                currency: 'USD',
                amountBase: $amount,
                memo: $memo,
                source: 'bank_statement',
                externalId: 'suspense-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();

        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        return $transaction->refresh();
    }

    /**
     * @template T of object
     * @param T $tool
     * @return T
     */
    private function tool(object $tool): object
    {
        return $tool->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
