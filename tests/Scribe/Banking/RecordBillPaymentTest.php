<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecordBillPaymentTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementAction;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchOutcomeEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Actions\FlagUnconfirmedBillPaymentsAction;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\DataTransferObject\RecordedBillPayment;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Scribe\Payments\Webhooks\BillPaymentWebhookJob;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Plan §4 "Pay": a person pays, and the bill is marked paid through one idempotent action whether the
 * news comes from the UI, the agent or a payment-provider webhook. The bank later confirms it.
 */
final class RecordBillPaymentTest extends BankStatementTestCase
{
    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount();
    }

    public function testTheSameReferenceRecordsOnePayment(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);

        $first = $this->record($bill, 300.00, 'TRX-555');
        $second = $this->record($bill->refresh(), 300.00, ' trx-555 ');

        $this->assertFalse($first->alreadyRecorded);
        $this->assertTrue($second->alreadyRecorded);
        $this->assertSame($first->payment->getId(), $second->payment->getId());
        $this->assertSame(1, BillPaymentAllocation::query()->where('bill_id', $bill->getId())->count());
        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->refresh()->document_status);
        $this->assertSame(-300.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(PaymentRecordedViaEnum::UI, $first->payment->refresh()->recorded_via);
    }

    public function testWithoutAReferenceEachCallIsAPayment(): void
    {
        $bill = $this->receivedBill('Edesur', 300.00);

        $this->record($bill, 100.00, null);
        $this->record($bill->refresh(), 100.00, null);

        $this->assertSame(2, BillPaymentAllocation::query()->where('bill_id', $bill->getId())->count());
        $this->assertSame(100.00, (float) $bill->refresh()->balance_due_native);
    }

    public function testTheAgentToolDefaultsToTheBalanceAndIsIdempotentWhenToldTwice(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);

        $first = $this->tool()(bill_id: $bill->getId());
        // A fresh tool instance is a later turn: the per-turn guard is gone, the action's key is not.
        $second = $this->tool()(bill_id: $bill->getId(), amount: 300.00);

        $this->assertTrue($first['success'], json_encode($first));
        $this->assertFalse($first['already_recorded']);
        $this->assertSame('paid', $first['bill_status']);
        $this->assertTrue($second['already_recorded']);
        $this->assertSame($first['payment_id'], $second['payment_id']);
    }

    public function testTheAgentToolPointsAtSuspenseWhenTheBankAlreadyHasThePayment(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);
        $parked = $this->bankMovement(300.00, 'Unknown transfer', Carbon::parse('2026-06-02'));
        new MatchBankTransactionAction($parked, static::$cachedUser)->execute();

        $result = $this->tool()(bill_id: $bill->getId());

        $this->assertFalse($result['success']);
        $this->assertSame($parked->getId(), $result['bank_transaction_id']);
        $this->assertStringContainsString('settle_from_suspense', $result['note']);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->refresh()->document_status);
    }

    public function testAStatementRowConfirmsARecordedPaymentInsteadOfPostingCashAgain(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);
        $recorded = $this->record($bill, 300.00, 'TRX-777');

        $movement = $this->bankMovement(300.00, 'Transferencia TRX-777', Carbon::parse('2026-06-04'));
        $outcome = new MatchBankTransactionAction($movement, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::CONFIRMED_PAYMENT, $outcome);
        $movement->refresh();
        $this->assertSame(BankTransactionMatchedToTypeEnum::BILL_PAYMENT, $movement->matched_to_type);
        $this->assertSame($recorded->payment->getId(), $movement->matched_to_id);
        $this->assertNotNull($recorded->payment->refresh()->reconciled_at);
        $this->assertSame(-300.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING), 'Cash credited once, by the payment.');
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testAPaymentTheBankNeverShowsIsFlaggedOnceCoverageReachesPastTheGraceWindow(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);
        $recorded = $this->record(
            $bill,
            300.00,
            'TRX-404',
            Carbon::parse('2026-06-01')
        );

        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));
        $flagger = new FlagUnconfirmedBillPaymentsAction($this->kanvasApp, $this->company, graceDays: 10);

        $this->assertSame([], $flagger->execute(), 'No statement covers those days yet: not missing, just unseen.');

        new IngestBankStatementAction(
            app: $this->kanvasApp,
            company: $this->company,
            statement: new ParsedBankStatement(
                lines: [$this->line(
                    '2026-06-03',
                    -12.00,
                    988.00,
                    'Fee'
                )],
                periodEnd: Carbon::parse('2026-06-20'),
            ),
            source: BankStatementSourceEnum::CSV,
            bankAccount: $this->account,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame([$recorded->payment->getId()], $flagger->execute());
        $this->assertSame([], $flagger->execute(), 'Flagged once.');
        $this->assertNotNull($recorded->payment->refresh()->unconfirmed_flagged_at);
        $this->assertArrayNotHasKey('unconfirmed_flagged_at', $recorded->payment->metadata ?? []);
        $this->assertTrue(
            Event::query()
                ->where('event_type', 'accounting.payment.unconfirmed_by_bank')
                ->where('source_entity_id', $recorded->payment->getId())
                ->exists(),
        );
    }

    public function testTheProviderWebhookMapsItsPayloadAndIsIdempotentOnRetry(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);
        $receiver = $this->paymentReceiver([
            'shared_secret' => 'hook',
            'mapping' => [
                'bill_number' => 'data.invoice.number',
                'amount' => 'data.amount',
                'reference' => 'data.id',
                'paid_at' => 'data.paid_at',
            ],
        ]);
        $payload = ['data' => [
            'id' => 'pay_123',
            'amount' => '300.00',
            'paid_at' => '2026-06-03',
            'invoice' => ['number' => $bill->bill_number],
        ]];

        $first = $this->runPaymentWebhook($receiver, $payload);
        $retry = $this->runPaymentWebhook($receiver, $payload);

        $this->assertTrue($first['recorded'], json_encode($first));
        $this->assertFalse($first['already_recorded']);
        $this->assertTrue($retry['already_recorded']);
        $this->assertSame($first['payment_id'], $retry['payment_id']);
        $this->assertSame('paid', $first['bill_status']);
        $this->assertSame('payment_provider', Payment::query()->find($first['payment_id'])->source);
    }

    public function testTheProviderWebhookRefusesAPaymentWithoutAReference(): void
    {
        $bill = $this->receivedBill('Edesur', 300.00);
        $receiver = $this->paymentReceiver(['shared_secret' => 'hook']);

        $result = $this->runPaymentWebhook($receiver, ['bill_id' => $bill->getId(), 'amount' => 300]);

        $this->assertFalse($result['recorded']);
        $this->assertSame('missing_reference', $result['reason']);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->refresh()->document_status);

        $request = Request::create('/receiver/x', 'POST');
        $request->headers->set('X-Webhook-Secret', 'nope');
        $this->assertFalse(BillPaymentWebhookJob::authenticateRequest($request, $receiver));
    }

    public function testTheUiMutationIsIdempotentOnTheReference(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);
        $mutation = 'mutation($id: ID!, $input: ScribeAllocatePaymentInput!) {
            allocateScribeBillPayment(id: $id, input: $input) { id amount_native }
        }';
        $variables = ['id' => $bill->getId(), 'input' => ['amount_native' => 300.0, 'reference' => 'UI-1']];

        $first = $this->graphQL($mutation, $variables)->json('data.allocateScribeBillPayment');
        $second = $this->graphQL($mutation, $variables)->json('data.allocateScribeBillPayment');

        $this->assertNotNull($first);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->refresh()->document_status);
    }

    private function record(
        Bill $bill,
        float $amount,
        ?string $reference,
        ?Carbon $paidAt = null
    ): RecordedBillPayment {
        return new RecordBillPaymentAction(
            bill: $bill,
            amountNative: $amount,
            recordedVia: PaymentRecordedViaEnum::UI,
            reference: $reference,
            paidAt: $paidAt,
            user: static::$cachedUser,
        )->execute();
    }

    private function tool(): RecordBillPaymentTool
    {
        return new RecordBillPaymentTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function bankMovement(float $amount, string $memo, Carbon $date): BankTransaction
    {
        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->kanvasApp,
                company: $this->company,
                bankAccount: $this->account,
                postedAt: $date,
                transactionDate: $date,
                direction: BankTransactionDirectionEnum::DEBIT,
                amountNative: $amount,
                currency: 'USD',
                amountBase: $amount,
                memo: $memo,
                source: 'bank_statement',
                externalId: 'pay-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function paymentReceiver(array $configuration): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => BillPaymentWebhookJob::class],
            ['name' => 'BillPaymentWebhookJob'],
        );

        return ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user(static::$cachedUser->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => $configuration,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPaymentWebhook(ReceiverWebhook $receiver, array $payload): array
    {
        $call = new ProcessWebhookAttemptAction(
            $receiver,
            Request::create('https://localhost/v1/receiver/' . $receiver->uuid, 'POST', $payload),
        )->execute();

        return new BillPaymentWebhookJob($call)->execute();
    }
}
