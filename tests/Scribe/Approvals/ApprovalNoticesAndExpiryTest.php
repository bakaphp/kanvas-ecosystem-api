<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Actions\ExpireApprovalRequestsAction;
use Kanvas\Approvals\Actions\NotifyApprovalExpiredAction;
use Kanvas\Approvals\Contracts\DeliversApprovalNoticesInterface;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\ApprovalExpiredNotification;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum as InvoiceConfigurationEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;

/**
 * Notification lives in the approval request, not in whichever caller opened it: a bill submitted from
 * anywhere DMs its approver on Slack (email when Slack can't reach them), an invoice uses the AR notifier,
 * and an expired request walks the bill back to DRAFT and tells the people involved.
 */
class ApprovalNoticesAndExpiryTest extends ScribeTestCase
{
    use SeedsBillApprovals;

    private mixed $originalApNotifier = null;
    private mixed $originalArNotifier = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // App settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalApNotifier = $this->kanvasApp->get(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value);
        $this->originalArNotifier = $this->kanvasApp->get(InvoiceConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value);
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, '');
        $this->kanvasApp->set(InvoiceConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value, '');
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, $this->originalApNotifier);
        $this->kanvasApp->set(InvoiceConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value, $this->originalArNotifier);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_bill_submitted_outside_the_agent_dms_its_approver_on_slack_and_sends_no_email(): void
    {
        $notifier = $this->notifierAgent();
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, (string) $notifier->getId());
        $this->fakeSlack(reachable: true);

        $vendor = $this->seedTestOrganization('Slack Notice Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) ($request['markdown_text'] ?? ''), "approve bill {$bill->getId()}"));
        Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
        $this->assertNotNull($bill->pendingApproval()->approvers()->first()->notified_at);
    }

    public function test_an_approver_slack_cannot_reach_gets_the_email_instead(): void
    {
        $notifier = $this->notifierAgent();
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, (string) $notifier->getId());
        $this->fakeSlack(reachable: false);

        $vendor = $this->seedTestOrganization('Email Notice Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $this->submitBill($vendor);

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage'));
    }

    public function test_an_invoice_uses_the_ar_notifier_not_the_ap_one(): void
    {
        $apAgent = $this->notifierAgent('Apex');
        $arAgent = $this->notifierAgent('Arc');
        $this->kanvasApp->set(ApprovalConfigurationEnum::SLACK_NOTIFIER_AGENT_ID->value, (string) $apAgent->getId());
        $this->kanvasApp->set(InvoiceConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value, (string) $arAgent->getId());

        $invoice = new Invoice();
        $invoice->apps_id = $this->kanvasApp->getId();
        $invoice->companies_id = $this->company->getId();

        $this->assertSame((string) $arAgent->getId(), $invoice->approvalNotifierAgentId());
        $this->assertSame((string) $apAgent->getId(), $this->submitBill($this->seedTestOrganization('AP Notifier Vendor'))->approvalNotifierAgentId());
    }

    public function test_an_expired_request_returns_the_bill_to_draft_with_a_note_and_notifies(): void
    {
        $vendor = $this->seedTestOrganization('Expiry Vendor');
        $approver = $this->linkApprover($vendor);
        $policy = $this->seedBillPolicy();
        $policy->expires_after_hours = 24;
        $policy->saveOrFail();

        $bill = $this->submitBill($vendor);
        $request = $bill->pendingApproval();
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);

        Carbon::setTestNow(Carbon::now()->addHours(25));
        new ExpireApprovalRequestsAction($this->kanvasApp)->execute();

        $bill->refresh();
        $this->assertSame(ApprovalStatusEnum::EXPIRED, $request->refresh()->status);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertStringContainsString("Approval request #{$request->getId()} expired", (string) $bill->internal_notes);
        Notification::assertSentTo($approver, ApprovalExpiredNotification::class);
        Notification::assertSentTo(static::$cachedUser, ApprovalExpiredNotification::class);
    }

    public function test_an_expiry_notice_whose_direct_channel_throws_still_goes_by_email(): void
    {
        $vendor = $this->seedTestOrganization('Throwing Channel Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();
        $request = $this->submitBill($vendor)->pendingApproval();

        $entity = new class () extends Model implements DeliversApprovalNoticesInterface {
            public function deliverApprovalNotice(ApprovalRequest $request, Users $recipient): bool
            {
                throw new RuntimeException('Slack is down');
            }
        };

        new NotifyApprovalExpiredAction($request, $entity)->execute();

        Notification::assertSentTo($approver, ApprovalExpiredNotification::class);
        Notification::assertSentTo(static::$cachedUser, ApprovalExpiredNotification::class);
    }

    public function test_batch_approval_approves_each_request_and_reports_the_ones_it_could_not(): void
    {
        $vendor = $this->seedTestOrganization('Batch Vendor');
        $approver = $this->linkApprover($vendor, 'batch-approver');
        $otherVendor = $this->seedTestOrganization('Batch Other Vendor');
        $this->linkApprover($otherVendor, 'someone-else');
        $this->seedBillPolicy();

        $first = $this->submitBill($vendor);
        $second = $this->submitBill($vendor);
        $notMine = $this->submitBill($otherVendor);

        $ids = [
            $first->pendingApproval()->getId(),
            $second->pendingApproval()->getId(),
            $notMine->pendingApproval()->getId(),
            999999999,
        ];

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $approver)
            ->__invoke(approval_request_ids: $ids);

        $this->assertTrue($result['batch']);
        $this->assertSame(2, $result['approved_count']);
        $this->assertSame(2, $result['not_approved_count']);

        $byId = array_column($result['results'], null, 'approval_request_id');
        $this->assertTrue($byId[$ids[0]]['approved']);
        $this->assertTrue($byId[$ids[1]]['approved']);
        $this->assertSame('not_authorized', $byId[$ids[2]]['reason']);
        $this->assertSame('not_found', $byId[$ids[3]]['reason']);

        $this->assertNotNull($notMine->refresh()->pendingApproval(), 'An approver check failing on one item must not touch it.');
    }

    public function test_the_single_record_shape_still_works(): void
    {
        $vendor = $this->seedTestOrganization('Single Shape Vendor');
        $approver = $this->linkApprover($vendor, 'single-approver');
        $this->seedBillPolicy();
        $bill = $this->submitBill($vendor);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $approver)
            ->__invoke(target_type: 'bill', target_id: $bill->getId());

        $this->assertTrue($result['approved']);
        $this->assertArrayNotHasKey('batch', $result);
    }

    private function notifierAgent(string $name = 'Apex'): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => $name, 'user_id' => static::$cachedUser->getId()]);
        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');

        return $agent;
    }

    private function fakeSlack(bool $reachable): void
    {
        Http::fake([
            'slack.com/api/users.lookupByEmail' => $reachable
                ? Http::response(['ok' => true, 'user' => ['id' => 'U123']])
                : Http::response(['ok' => false, 'error' => 'users_not_found']),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);
    }
}
