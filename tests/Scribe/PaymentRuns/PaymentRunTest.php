<?php

declare(strict_types=1);

namespace Tests\Scribe\PaymentRuns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\BuildPaymentRunTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Scribe\PaymentRuns\Actions\BuildPaymentRunAction;
use Kanvas\Scribe\PaymentRuns\Actions\SendPaymentRunAction;
use Kanvas\Scribe\PaymentRuns\DataTransferObject\PaymentRun as PaymentRunData;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunLineStatusEnum;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Scribe\PaymentRuns\Services\PaymentRunPresenterService;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use Kanvas\Templates\Support\TemplateSandbox;
use Kanvas\Users\Models\Users;
use Tests\Scribe\AccountingOperationsTestCase;

/**
 * Plan §4 "Pay" / PR 4.4: the agent builds the run and sends it to the person who pays; the run follows
 * the payments that person reports and never moves money itself.
 */
final class PaymentRunTest extends AccountingOperationsTestCase
{
    public function testOnlyApprovedUnpaidBillsDueByTheDateAreGroupedByCurrencyWithACashCheck(): void
    {
        $usd = $this->bankAccount('USD', 1000.00);
        $this->bankAccount('DOP', null);

        $claro = $this->receivedBill(
            'Claro',
            300.00,
            'USD',
            '2026-06-10'
        );
        $edesur = $this->receivedBill(
            'Edesur',
            500.00,
            'USD',
            '2026-06-12'
        );
        $local = $this->receivedBill(
            'Ferreteria',
            20000.00,
            'DOP',
            '2026-06-05'
        );
        $later = $this->receivedBill(
            'Later',
            50.00,
            'USD',
            '2026-07-30'
        );

        $run = $this->build(Carbon::parse('2026-06-15'));

        $this->assertSame(PaymentRunStatusEnum::DRAFT, $run->status);
        $this->assertSame(3, $run->line_count);
        $this->assertEqualsCanonicalizing(
            [$claro->getId(), $edesur->getId(), $local->getId()],
            $run->lines()->pluck('bill_id')->all()
        );
        $this->assertNotContains($later->getId(), $run->lines()->pluck('bill_id')->all());

        $groups = collect($run->groups)->keyBy('currency');
        $this->assertSame($usd->getId(), $groups['USD']['bank_account_id']);
        $this->assertSame(800.0, (float) $groups['USD']['total']);
        $this->assertSame('enough', $groups['USD']['cash_check']);
        $this->assertSame('feed', $groups['USD']['balance_source']);
        $this->assertSame('unknown', $groups['DOP']['cash_check']);
        $this->assertSame(
            $usd->getId(),
            $run->lines()->where('bill_id', $claro->getId())->value('bank_account_id')
        );

        $this->assertTrue(Event::query()
            ->where('event_type', 'scribe.payment_run.built')
            ->where('source_entity_id', $run->getId())
            ->exists());
    }

    public function testACurrencyWhoseBalanceDoesNotCoverTheBillsIsFlaggedShort(): void
    {
        $this->bankAccount('USD', 100.00);
        $this->receivedBill('Claro', 300.00);

        $group = $this->build(Carbon::parse('2026-06-30'))->groups[0];

        $this->assertSame('short', $group['cash_check']);
        $this->assertSame(200.0, (float) $group['shortfall']);
    }

    public function testABillAlreadyOnAnOpenRunIsNotOfferedTwice(): void
    {
        $this->bankAccount('USD', 5000.00);
        $first = $this->receivedBill('Claro', 300.00);

        $this->build(Carbon::parse('2026-06-30'));
        $second = $this->receivedBill('Edesur', 200.00);
        $run = $this->build(Carbon::parse('2026-06-30'));

        $this->assertSame([$second->getId()], $run->lines()->pluck('bill_id')->all());
        $this->assertSame('already_on_run', $run->metadata['skipped'][0]['reason']);
        $this->assertSame($first->getId(), $run->metadata['skipped'][0]['bill_id']);

        $this->expectException(ValidationException::class);
        $this->build(Carbon::parse('2026-06-30'));
    }

    public function testASelectedBillThatIsNotApprovedIsReportedNotPaid(): void
    {
        $approved = $this->receivedBill('Claro', 300.00);
        $pending = $this->pendingBill();

        $run = $this->build(Carbon::parse('2026-06-30'), [$approved->getId(), $pending->getId()]);

        $this->assertSame([$approved->getId()], $run->lines()->pluck('bill_id')->all());
        $this->assertSame($pending->getId(), $run->metadata['skipped'][0]['bill_id']);
    }

    public function testSendingEmailsTheRunToThePayerAndRefusesSomeoneOutsideTheCompany(): void
    {
        $this->bankAccount('USD', 5000.00);
        $this->receivedBill('Claro', 300.00);
        $run = $this->build(Carbon::parse('2026-06-30'));

        $sent = new SendPaymentRunAction($run, static::$cachedUser)->execute();

        $this->assertSame(PaymentRunStatusEnum::SENT, $sent->status);
        $this->assertSame(static::$cachedUser->getId(), $sent->sent_to_users_id);
        Notification::assertSentTo(
            static::$cachedUser,
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === ScribeEmailTemplateEnum::PAYMENT_RUN->value
        );

        $outsider = Users::factory()->create();
        $this->expectException(ValidationException::class);
        new SendPaymentRunAction($run, $outsider)->execute();
    }

    public function testTheRunFollowsThePaymentsThePersonReports(): void
    {
        $this->bankAccount('USD', 5000.00);
        $claro = $this->receivedBill('Claro', 300.00);
        $edesur = $this->receivedBill('Edesur', 200.00);
        $run = new SendPaymentRunAction($this->build(Carbon::parse('2026-06-30')), static::$cachedUser)->execute();

        $this->pay($claro->refresh(), 100.00, 'TRX-1');
        $this->assertSame(PaymentRunStatusEnum::PARTIALLY_PAID, $run->refresh()->status);
        $this->assertSame(
            PaymentRunLineStatusEnum::PARTIALLY_PAID,
            $run->lines()->where('bill_id', $claro->getId())->first()->status
        );

        $this->pay($claro->refresh(), 200.00, 'TRX-2');
        $this->pay($edesur->refresh(), 200.00, 'TRX-3');

        $run->refresh();
        $this->assertSame(PaymentRunStatusEnum::PAID, $run->status);
        $this->assertNotNull($run->paid_at);
        $this->assertSame(0, $run->lines()->where('status', '!=', PaymentRunLineStatusEnum::PAID->value)->count());
        $this->assertSame(2, Event::query()
            ->where('event_type', 'scribe.payment_run.status_changed')
            ->where('source_entity_id', $run->getId())
            ->count());
    }

    public function testTheToolBuildsSendsToTheCompanyPayerAndSummarises(): void
    {
        $this->bankAccount('USD', 5000.00);
        $this->receivedBill('Claro', 300.00);

        $result = $this->tool()->__invoke(due_by: '2026-06-30');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['built']);
        $this->assertSame(static::$cachedUser->email, $result['sent_to']);
        $this->assertSame('sent', $result['status']);
        $this->assertStringContainsString('USD 300.00', $result['summary']);
        $this->assertStringContainsString('enough cash', $result['summary']);
        Notification::assertSentTo(static::$cachedUser, Blank::class);
    }

    public function testTheToolRefusesAPayerWhoIsNotAUserAndBadInput(): void
    {
        $this->receivedBill('Claro', 300.00);

        $stranger = $this->tool()->__invoke(payer_email: 'nobody-' . uniqid() . '@example.com');
        $badDate = $this->tool()->__invoke(due_by: 'next friday');
        $nothing = $this->tool()->__invoke(due_by: '2026-06-01');

        $this->assertFalse($stranger['success']);
        $this->assertFalse($stranger['built']);
        $this->assertFalse($badDate['success']);
        $this->assertFalse($nothing['built']);
        $this->assertSame(0, PaymentRun::query()->where('companies_id', $this->company->getId())->count());
        Notification::assertNothingSent();
    }

    public function testTheEmailTemplateRendersTheRunInTheSandbox(): void
    {
        $this->bankAccount('USD', 100.00);
        $this->receivedBill('Claro', 300.00);
        $run = $this->build(Carbon::parse('2026-06-30'));

        $html = TemplateSandbox::renderHtml(
            (string) file_get_contents(resource_path(ScribeEmailTemplateEnum::PAYMENT_RUN->file())),
            [
                ...new PaymentRunPresenterService()->toArray($run),
                'company_name' => 'MCTEKK',
                'payer_name' => 'Ana',
            ]
        );

        $this->assertStringContainsString('Payment run #' . $run->getId(), $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('Short by 200.00', $html);
    }

    /**
     * @param list<int>|null $billIds
     */
    private function build(Carbon $dueBy, ?array $billIds = null): PaymentRun
    {
        return new BuildPaymentRunAction(new PaymentRunData(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            dueBy: $dueBy,
            billIds: $billIds,
        ))->execute();
    }

    private function pay(Bill $bill, float $amount, string $reference): void
    {
        new RecordBillPaymentAction(
            bill: $bill,
            amountNative: $amount,
            recordedVia: PaymentRecordedViaEnum::AGENT,
            reference: $reference,
            user: static::$cachedUser,
        )->execute();
    }

    private function pendingBill(): Bill
    {
        $bill = $this->receivedBill('Pending', 10.00);
        $bill->document_status = BillDocumentStatusEnum::PENDING_APPROVAL;
        $bill->saveQuietly();

        return $bill;
    }

    private function tool(): BuildPaymentRunTool
    {
        return new BuildPaymentRunTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
