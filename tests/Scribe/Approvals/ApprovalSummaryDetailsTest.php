<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;

/** An approver sees who a bill is from and how much it is for, not only its number, which two vendors can share. */
class ApprovalSummaryDetailsTest extends ScribeTestCase
{
    use SeedsBillApprovals;

    private mixed $originalApNotifier = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // App settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalApNotifier = $this->kanvasApp->get(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value);
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, '');
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, $this->originalApNotifier);

        parent::tearDown();
    }

    public function test_the_summary_of_a_bill_under_approval_names_the_vendor_and_the_amount(): void
    {
        $request = $this->requestFor('Summary Details Vendor');

        $summary = $request->entitySummary();

        $this->assertSame(['id', 'type', 'label', 'details'], array_keys($summary));
        $this->assertSame('Summary Details Vendor', $summary['details']['party']);
        $this->assertSame(500.0, $summary['details']['amount']);
        $this->assertSame('USD', $summary['details']['currency']);
        $this->assertNull($summary['details']['due_date']);
    }

    public function test_the_approval_email_carries_the_vendor_and_the_amount(): void
    {
        // An app that synced its templates before the details line existed still stores the older copy.
        new CreateTemplateAction(new TemplateInput(
            app: $this->kanvasApp,
            name: ApprovalEmailTemplateEnum::REQUESTED->value,
            template: file_get_contents(resource_path('views/emails/approvalRequested.twig')),
            company: $this->company,
        ))->execute();
        $request = $this->requestFor('Email Details Vendor');

        $notification = new ApprovalRequestedNotification($request);

        $subject = (string) $notification->getSubject();
        // The subject is echoed around the body, and would satisfy the body assertion on its own.
        $body = str_replace(e($subject), '', $notification->getEmailContent());

        $this->assertStringContainsString('Email Details Vendor · USD 500.00', $subject);
        $this->assertStringContainsString('Email Details Vendor · USD 500.00', $body);
    }

    public function test_a_due_date_is_shown_when_the_bill_has_one(): void
    {
        $request = $this->requestFor('Due Date Vendor');
        Bill::query()->whereKey($request->entity_id)->update(['due_date' => '2026-12-01']);

        $line = $request->fresh()->emailSummary()['details'];

        $this->assertSame('Due Date Vendor · USD 500.00 · due 2026-12-01', $line);
    }

    public function test_an_invoice_describes_its_customer_amount_and_due_date(): void
    {
        $invoice = new Invoice();
        $invoice->billable_display_name = 'Acme Customer';
        $invoice->total_native = 1250.5;
        $invoice->currency = 'USD';
        $invoice->due_date = '2026-12-15';

        $this->assertSame(
            ['party' => 'Acme Customer', 'amount' => 1250.5, 'currency' => 'USD', 'due_date' => '2026-12-15'],
            $invoice->approvalDetails()
        );
    }

    private function requestFor(string $vendorName): ApprovalRequest
    {
        $vendor = $this->seedTestOrganization($vendorName);
        $this->linkApprover($vendor);
        $this->seedBillPolicy();

        return $this->submitBill($vendor)->pendingApproval();
    }
}
