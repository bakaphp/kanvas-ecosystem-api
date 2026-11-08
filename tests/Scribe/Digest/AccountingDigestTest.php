<?php

declare(strict_types=1);

namespace Tests\Scribe\Digest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Models\ApprovalRequestApprover;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AccountingDigestTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Digest\Actions\SendAccountingDigestAction;
use Kanvas\Scribe\Digest\Services\AccountingDigestBuilderService;
use Kanvas\Scribe\Digest\Services\AccountingDigestSummaryService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Support\TemplateSandbox;
use Kanvas\Users\Models\Users;
use Tests\Scribe\AccountingOperationsTestCase;
use Throwable;

/**
 * PR 2.9: one AR/AP digest per company per day, to the people the accounting agents report to, from Scribe
 * data and ledger events; nothing on a quiet day.
 */
final class AccountingDigestTest extends AccountingOperationsTestCase
{
    private const string DAY = '2026-06-01';

    public function testAQuietDaySendsNothingAndACompanyWithoutAccountingAgentsIsSkipped(): void
    {
        $this->receivedBill('Claro', 300.00);
        $this->assertSame(0, $this->send(), 'No AR/AP agent: no digest, however busy the day.');

        $this->accountingAgent(AccountsReceivableAgent::class, $this->person());
        $quiet = new SendAccountingDigestAction($this->kanvasApp, $this->company, Carbon::parse('2026-06-10'))->execute();

        $this->assertSame(0, $quiet);
        Notification::assertNothingSent();
        $this->assertFalse(Event::query()
            ->where('companies_id', $this->company->getId())
            ->where('event_type', 'scribe.accounting_digest.sent')
            ->exists());
    }

    public function testTheDayIsCountedAndSentToWhoeverHiredTheAgent(): void
    {
        $creator = $this->person();
        $this->accountingAgent(AccountsPayableAgent::class, $creator);
        $this->bankAccount('USD', 1000.00);

        $customer = $this->seedTestOrganization('Cliente ' . uniqid());
        $this->issueTestInvoice(
            $customer,
            500.00,
            issuedDate: self::DAY,
            dueDate: '2026-06-05'
        );
        $paid = $this->receivedBill('Claro', 300.00);
        $this->receivedBill(
            'Edesur',
            200.00,
            'USD',
            '2026-06-04'
        );
        new RecordBillPaymentAction(
            bill: $paid,
            amountNative: 300.00,
            recordedVia: PaymentRecordedViaEnum::AGENT,
            reference: 'TRX-9',
            user: static::$cachedUser,
        )->execute();
        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            4,
            1000,
            '2026-06-20'
        );
        $this->approvalTrail($creator);

        $sent = $this->send();

        $this->assertSame(1, $sent);
        Notification::assertSentTo(
            $creator,
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === ScribeEmailTemplateEnum::ACCOUNTING_DIGEST->value
        );

        $digest = new AccountingDigestBuilderService($this->kanvasApp, $this->company, Carbon::parse(self::DAY))->build();
        $this->assertSame(1, $digest['ar']['invoices_issued']);
        $this->assertSame([], $digest['ar']['engagements']);
        $this->assertSame([['currency' => 'USD', 'count' => 1, 'amount' => 500.0]], $digest['ar']['collections_due']);
        $this->assertSame(2, $digest['ap']['bills_received']);
        $this->assertSame(2, $digest['ap']['bills_approved']);
        $this->assertSame(1, $digest['ap']['bills_paid']);
        $this->assertSame(1, $digest['ap']['payments_recorded']);
        $this->assertSame([['currency' => 'USD', 'count' => 1, 'amount' => 200.0]], $digest['ap']['bills_due_soon']);
        $this->assertCount(1, $digest['banking']['accounts']);
        $this->assertSame(['expiry'], $digest['sequences'][0]['reasons']);
        $this->assertSame(1, $digest['approvals']['waiting']);
        $this->assertSame(2, $digest['approvals']['approvers'][0]['decided']);
        $this->assertSame(0.5, $digest['approvals']['approvers'][0]['rejection_rate']);
        $this->assertSame(3.0, $digest['approvals']['approvers'][0]['avg_hours']);
        $this->assertNull($digest['issue_ecf']);

        $ledger = Event::query()
            ->where('companies_id', $this->company->getId())
            ->where('event_type', 'scribe.accounting_digest.sent')
            ->first();
        $this->assertNotNull($ledger);
        $this->assertStringContainsString('AR: 1 invoice(s) issued', $ledger->payload['summary']);
        $this->assertStringContainsString('0 opened', $ledger->payload['summary']);
        $this->assertStringContainsString('Sequence E31 needs a new range', $ledger->payload['summary']);
    }

    public function testTheStandingLineSaysWhenAndByWhomAgentInvoiceApprovalWasTurnedOff(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();

        $this->assertNull($this->build()['issue_ecf']);

        $policy = ApprovalPolicy::query()
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', 'approve_invoice')
            ->firstOrFail();
        $steps = $policy->steps;
        $steps[0]['when'] = ['field' => 'total', 'operator' => '>', 'value' => 100000];
        $policy->steps = $steps;
        $policy->saveOrFail();
        $this->assertNull($this->build()['issue_ecf'], 'A partial switch-off still gates some agent invoices.');

        $steps[0]['when'] = ['field' => 'origin', 'operator' => '==', 'value' => 'nobody'];
        $policy->steps = $steps;
        $policy->changedBy = static::$cachedUser;
        $policy->saveOrFail();

        $line = $this->build()['issue_ecf'];
        $this->assertSame('no step applies to agent invoices', $line['how']);
        $this->assertSame(self::DAY, $line['since']);
        $this->assertSame(trim(static::$cachedUser->firstname . ' ' . static::$cachedUser->lastname) ?: static::$cachedUser->email, $line['by']);
        $this->assertStringContainsString('issue_ecf: agent invoice approval is OFF', new AccountingDigestSummaryService()->text($this->build()));
    }

    public function testTheCommandSendsPerCompanyAndTheToolReturnsTheSameSummary(): void
    {
        $creator = $this->person();
        $this->accountingAgent(AccountsReceivableAgent::class, $creator);
        $this->receivedBill('Claro', 300.00);

        $this->artisan('kanvas:scribe:send-accounting-digest', [
            '--app' => $this->kanvasApp->getId(),
            '--company' => $this->company->getId(),
            '--date' => self::DAY,
        ])->assertSuccessful();
        Notification::assertSentTo($creator, Blank::class);

        $result = new AccountingDigestTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(date: self::DAY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['has_activity']);
        $this->assertStringContainsString('AP: 1 bill(s) received', $result['summary']);
        $this->assertFalse(new AccountingDigestTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(date: 'yesterday')['success']);
    }

    public function testTheEmailTemplateRendersTheDigestInTheSandbox(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $this->receivedBill('Claro', 300.00);
        $digest = $this->build();
        $digest['issue_ecf'] = ['how' => 'policy deleted', 'since' => '2026-05-30', 'by' => 'Ana'];

        $html = TemplateSandbox::renderHtml(
            (string) file_get_contents(resource_path(ScribeEmailTemplateEnum::ACCOUNTING_DIGEST->file())),
            $digest
        );

        $this->assertStringContainsString('Accounting digest — ' . self::DAY, $html);
        $this->assertStringContainsString('issue_ecf', $html);
        $this->assertStringContainsString('by Ana', $html);
        $this->assertStringContainsString('0 opened', $html);

        $digest['ar']['engagements'] = null;
        $this->assertStringContainsString('opens not tracked yet', TemplateSandbox::renderHtml(
            (string) file_get_contents(resource_path(ScribeEmailTemplateEnum::ACCOUNTING_DIGEST->file())),
            $digest
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return new AccountingDigestBuilderService($this->kanvasApp, $this->company, Carbon::parse(self::DAY))->build();
    }

    private function send(): int
    {
        return new SendAccountingDigestAction($this->kanvasApp, $this->company, Carbon::parse(self::DAY))->execute();
    }

    private function person(): Users
    {
        return Users::factory()->create(['email' => 'digest-' . uniqid() . '@example.com']);
    }

    /**
     * One request still waiting, and two decided by $approver in the last 30 days: one approved, one rejected,
     * each three hours after being notified.
     */
    private function approvalTrail(Users $approver): void
    {
        try {
            $moduleId = SystemModulesRepository::getByModelName(Bill::class, $this->kanvasApp)->getId();
        } catch (Throwable) {
            $moduleId = 0;
        }

        foreach ([ApprovalStatusEnum::PENDING, ApprovalStatusEnum::APPROVED, ApprovalStatusEnum::REJECTED] as $status) {
            $request = ApprovalRequest::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->company->getId(),
                'system_modules_id' => $moduleId,
                'entity_id' => 1,
                'approval_type' => 'approve_bill',
                'status' => $status->value,
                'current_step' => 1,
            ]);

            if ($status === ApprovalStatusEnum::PENDING) {
                continue;
            }

            ApprovalRequestApprover::create([
                'approval_requests_id' => $request->getId(),
                'users_id' => $approver->getId(),
                'step' => 1,
                'decision' => $status === ApprovalStatusEnum::APPROVED ? ApprovalDecisionEnum::APPROVED->value : ApprovalDecisionEnum::REJECTED->value,
                'notified_at' => Carbon::parse('2026-05-30 09:00:00'),
                'decided_at' => Carbon::parse('2026-05-30 12:00:00'),
            ]);
        }
    }
}
