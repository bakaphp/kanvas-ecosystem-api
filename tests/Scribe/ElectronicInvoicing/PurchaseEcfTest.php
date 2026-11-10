<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\IssuePurchaseEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\SubmitPurchaseEcfForApprovalAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * Plan PR 4.5: E41 / E43 / E47 issued from bills and expenses through the same allocate → approve →
 * submit path as invoices, without posting anything (the purchase is already booked).
 */
class PurchaseEcfTest extends EcfCompletionTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();
    }

    public function test_a_person_issues_an_e41_on_a_bill_with_the_supplier_as_counterparty_and_its_withholdings(): void
    {
        $bill = $this->receivedBill(regionalCompliance: ['do_ecf' => ['withholding' => ['itbis_percent' => 100, 'isr_percent' => 10]]]);
        $billNumber = $bill->bill_number;
        $jeCount = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->getId())->count();

        $bill = new IssuePurchaseEcfAction($bill, EcfTypeEnum::COMPRAS)->execute();

        $ecf = EcfCompliance::read($bill);
        $this->assertSame('E410000000001', $ecf['encf']);
        $this->assertSame('41', $ecf['type']);
        $this->assertSame('issued', $ecf['direction']);
        $this->assertSame(EcfStatusEnum::WAITING->value, $ecf['status']);
        $this->assertSame($billNumber, $bill->bill_number, "The vendor's own number is kept.");
        $this->assertSame($jeCount, JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->getId())->count());
        Queue::assertPushed(SubmitEcfJob::class, fn (SubmitEcfJob $job): bool => $job->fiscalDocument->entity->is($bill));

        $this->accept($bill);

        $sent = FakeEcfProvider::$submitted[0];
        $this->assertSame(EcfTypeEnum::COMPRAS, $sent->type);
        $this->assertSame('00112345678', $sent->buyer->taxId);
        $this->assertSame('Juan Pérez Plomería', $sent->buyer->name);
        $this->assertSame(1800.0, $sent->lines[0]->itbisWithheld);
        $this->assertSame(1000.0, $sent->lines[0]->isrWithheld);
        $this->assertSame(1800.0, $sent->totals->itbisWithheld);
        $this->assertSame(11800.0, $sent->totals->total);

        $bill->refresh();
        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($bill));
        $this->assertTrue($this->ledgerHas($bill, 'scribe.bill.ecf_accepted'));
    }

    public function test_an_e43_is_issued_on_an_approved_expense_without_a_counterparty_tax_id(): void
    {
        $expense = $this->approveTestExpense(2500.0);

        $expense = new IssuePurchaseEcfAction($expense, EcfTypeEnum::GASTOS_MENORES)->execute();

        $this->assertSame('E430000000001', EcfCompliance::read($expense)['encf']);
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($expense))->handle();
        $this->assertSame(EcfTypeEnum::GASTOS_MENORES, FakeEcfProvider::$submitted[0]->type);
        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($expense->refresh()));
    }

    public function test_an_e47_carries_the_foreign_payee_and_payment_details(): void
    {
        $bill = $this->receivedBill(
            vendorTaxId: 'GB123456789',
            lines: [[500.0, 0.0]],
            regionalCompliance: ['do_ecf' => ['foreign_payment' => [
                'account_type' => '1',
                'account_number' => '000123456',
                'bank' => 'Banco Popular',
                'currency' => 'usd',
                'amount' => 500,
            ]]],
        );

        $bill = new IssuePurchaseEcfAction($bill, EcfTypeEnum::PAGOS_EXTERIOR)->execute();
        $this->accept($bill);

        $sent = FakeEcfProvider::$submitted[0];
        $this->assertTrue($sent->buyer->isForeign);
        $this->assertSame('GB123456789', $sent->buyer->taxId);
        $this->assertSame('000123456', $sent->foreignPayment->accountNumber);
        $this->assertSame('USD', $sent->foreignPayment->foreignCurrency);
        $this->assertSame(500.0, $sent->foreignPayment->foreignAmount);
    }

    public function test_an_agent_request_waits_for_approval_then_issues_the_type_the_approver_signed(): void
    {
        $bill = $this->receivedBill();

        ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Bill => new SubmitPurchaseEcfForApprovalAction($bill, EcfTypeEnum::COMPRAS, static::$cachedUser)->execute()
        );

        $bill->refresh();
        $this->assertFalse(EcfCompliance::hasNumber($bill));
        $request = $bill->pendingApproval(EcfIssuanceService::PURCHASE_APPROVAL_TYPE);
        $this->assertNotNull($request);
        $this->assertSame('41', $request->payload['ecf_type']);

        $result = new ApproveAction($request, static::$cachedUser)->execute();

        $this->assertSame('E410000000001', $result->handlerResult['encf']);
        $this->assertSame('E410000000001', EcfCompliance::read($bill->refresh())['encf']);
    }

    public function test_an_agent_cannot_issue_directly(): void
    {
        $bill = $this->receivedBill();

        $this->expectException(ApprovalRequiredException::class);

        ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Bill => new IssuePurchaseEcfAction($bill, EcfTypeEnum::COMPRAS)->execute()
        );
    }

    public function test_a_person_submitting_is_closed_by_rule_and_issued(): void
    {
        $bill = $this->receivedBill();

        $bill = new SubmitPurchaseEcfForApprovalAction($bill, EcfTypeEnum::COMPRAS, static::$cachedUser)->execute();

        $this->assertSame('E410000000001', EcfCompliance::read($bill)['encf']);
    }

    public function test_a_vendor_e_cf_bill_never_gets_an_e41(): void
    {
        $bill = $this->receivedBill();
        $this->fiscalRowFor(
            $bill,
            'E310000000077',
            status: FiscalDocumentStatusEnum::ACCEPTED,
            direction: FiscalDirectionEnum::RECEIVED,
            issuerTaxId: '131794321',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("vendor's own electronic invoice");

        new IssuePurchaseEcfAction($bill, EcfTypeEnum::COMPRAS)->execute();
    }

    public function test_the_type_must_fit_the_document_and_the_document_must_be_booked(): void
    {
        try {
            new IssuePurchaseEcfAction($this->approveTestExpense(100.0), EcfTypeEnum::COMPRAS)->execute();
            $this->fail('An expense cannot carry an E41.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('E43', $e->getMessage());
        }

        $bill = $this->receivedBill();
        $bill->document_status = BillDocumentStatusEnum::DRAFT;
        $bill->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('booked');
        new IssuePurchaseEcfAction($bill, EcfTypeEnum::COMPRAS)->execute();
    }

    public function test_a_rejected_purchase_e_cf_flags_a_reissue_and_leaves_the_bill_alone(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        $bill = new IssuePurchaseEcfAction($this->receivedBill(), EcfTypeEnum::COMPRAS)->execute();

        $this->accept($bill);

        $ecf = EcfCompliance::read($bill->refresh());
        $this->assertSame(EcfStatusEnum::REJECTED->value, $ecf['status']);
        $this->assertTrue($ecf['burned']);
        $this->assertSame('reissue_ecf', $ecf['action_required']);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->document_status);
        $this->assertTrue($this->ledgerHas($bill, 'scribe.bill.ecf_rejected'));
    }
}
