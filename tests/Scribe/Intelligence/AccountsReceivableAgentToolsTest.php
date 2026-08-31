<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchInvoicesForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AddInvoiceNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AttachInvoiceFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateArCreditMemoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateArInvoiceTool;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum as InvoicesConfigurationEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Tests\Scribe\ScribeTestCase;

class AccountsReceivableAgentToolsTest extends ScribeTestCase
{
    public function test_find_customer_tool_returns_acumatica_code(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        $result = new FindCustomerTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(name: 'Acme Corp');

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $codes = array_column($result['customers'], 'acumatica_customer_code');
        $this->assertContains('C0000123', $codes);
    }

    public function test_find_invoice_returns_full_detail_or_not_found(): void
    {
        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-5150',
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 1250.0,
            'total_native' => 1250.0,
            'paid_native' => 250.0,
            'balance_due_native' => 1000.0,
            'subtotal_base' => 1250.0,
            'total_base' => 1250.0,
            'paid_base' => 250.0,
            'balance_due_base' => 1000.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'due_date' => Carbon::parse('2026-07-01'),
            'source' => 'acumatica',
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Kraken Elite 360',
            'quantity' => 5,
            'unit_price_native' => 250.0,
        ]);

        $found = new FindInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(invoice_number: 'INV-5150');
        $this->assertTrue($found['found']);
        $this->assertSame('Acme Corporation', $found['customer']);
        $this->assertSame(1000.0, (float) $found['balance_due_native']);
        $this->assertCount(1, $found['lines']);
        $this->assertSame('RL-KP336', $found['lines'][0]['sku']);

        $missing = new FindInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(invoice_number: 'DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }

    public function test_list_overdue_invoices_filters_by_customer(): void
    {
        $target = $this->seedTestOrganization('Overdue Target Inc');
        $other = $this->seedTestOrganization('Someone Else Inc');

        $overdueForTarget = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => DocumentTypeEnum::INVOICE,
            'invoice_number' => 'INV-OVERDUE-TARGET',
            'customer_organization_id' => $target->getId(),
            'billable_display_name' => 'Overdue Target Inc',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 500.0, 'total_native' => 500.0, 'paid_native' => 0.0, 'balance_due_native' => 500.0,
            'subtotal_base' => 500.0, 'total_base' => 500.0, 'paid_base' => 0.0, 'balance_due_base' => 500.0,
            'issued_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->subDays(10),
            'source' => 'kanvas',
        ]);

        Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => DocumentTypeEnum::INVOICE,
            'invoice_number' => 'INV-OVERDUE-OTHER',
            'customer_organization_id' => $other->getId(),
            'billable_display_name' => 'Someone Else Inc',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 700.0, 'total_native' => 700.0, 'paid_native' => 0.0, 'balance_due_native' => 700.0,
            'subtotal_base' => 700.0, 'total_base' => 700.0, 'paid_base' => 0.0, 'balance_due_base' => 700.0,
            'issued_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->subDays(10),
            'source' => 'kanvas',
        ]);

        $result = new ListOverdueInvoicesTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer: 'Overdue Target');

        $numbers = array_column($result['invoices'], 'invoice_number');
        $this->assertContains('INV-OVERDUE-TARGET', $numbers);
        $this->assertNotContains('INV-OVERDUE-OTHER', $numbers);
        $this->assertSame($overdueForTarget->invoice_number, $numbers[0]);
    }

    public function test_apply_ar_payment_reports_not_found_for_unknown_invoice(): void
    {
        $result = new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: 999999999, amount: 100.0, reference: 'CHK-1');

        $this->assertFalse($result['applied']);
        $this->assertSame('invoice_not_found', $result['reason']);
    }

    public function test_apply_ar_payment_reports_not_pushed_when_invoice_has_no_acumatica_ref(): void
    {
        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-NOPUSH',
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 300.0, 'total_native' => 300.0, 'paid_native' => 0.0, 'balance_due_native' => 300.0,
            'subtotal_base' => 300.0, 'total_base' => 300.0, 'paid_base' => 0.0, 'balance_due_base' => 300.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $result = new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: (int) $invoice->id, amount: 100.0, reference: 'CHK-1');

        $this->assertFalse($result['applied']);
        $this->assertSame('invoice_not_pushed', $result['reason']);
    }

    public function test_create_ar_invoice_refuses_an_empty_customer_name(): void
    {
        $result = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: '', amount: 50.0, memo: 'test invoice');

        $this->assertFalse($result['created']);
        $this->assertSame('customer_name_required', $result['reason']);
    }

    public function test_create_ar_invoice_leaves_it_open_with_no_auto_payment(): void
    {
        $customer = $this->seedTestOrganization('Open Invoice Customer');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000999');

        $result = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Open Invoice Customer', amount: 50.0, memo: 'test invoice');

        $this->assertTrue($result['created']);
        $this->assertArrayNotHasKey('payment_pushed', $result);
        $this->assertArrayNotHasKey('payment_ref', $result);

        $allocations = InvoicePaymentAllocation::query()
            ->where('invoice_id', $result['invoice_id'])
            ->count();
        $this->assertSame(0, $allocations);
    }

    public function test_create_ar_invoice_treats_an_explicit_null_push_flag_as_the_default(): void
    {
        // The LLM sends `"push_to_acumatica": null` for an omitted optional boolean, which used to
        // TypeError against a non-nullable `bool $push_to_acumatica = true` (Sentry KANVAS-ECOSYSTEM-67Z).
        $customer = $this->seedTestOrganization('Null Flag Customer');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0001001');

        $result = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                customer_name: 'Null Flag Customer',
                amount: 275.0,
                memo: 'Null push flag test',
                push_to_acumatica: null,
            );

        $this->assertTrue($result['created']);
        $this->assertNotSame('draft', $result['document_status']);
    }

    public function test_create_ar_invoice_with_push_to_acumatica_false_stops_at_draft(): void
    {
        $customer = $this->seedTestOrganization('Pending Invoice Customer');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0001000');

        $result = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Pending Invoice Customer', amount: 275.0, memo: 'Pending flow test', push_to_acumatica: false);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['invoice_pushed']);
        $this->assertSame('draft', $result['document_status']);
        $this->assertArrayNotHasKey('invoice_ref', $result);
        $this->assertArrayNotHasKey('acumatica_invoice_id', $result);
        $this->assertSame('NOT IN APPROVER LIST', $result['approved_by_flag']);

        $invoice = Invoice::query()->where('id', $result['invoice_id'])->first();
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
    }

    public function test_create_ar_invoice_leaves_the_approved_by_flag_blank_when_an_approver_is_configured(): void
    {
        $customer = $this->seedTestOrganization('Flagged Invoice Customer');
        $customer->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'approver-' . uniqid() . '@example.test');

        $result = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Flagged Invoice Customer', amount: 275.0, memo: 'Has approver test', push_to_acumatica: false);

        $this->assertTrue($result['created']);
        $this->assertSame('', $result['approved_by_flag']);
    }

    public function test_approve_pending_item_requires_the_configured_approver(): void
    {
        $customer = $this->seedTestOrganization('Approval Flow Customer');
        $customer->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'someone-else-' . uniqid() . '@example.test');

        $created = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Approval Flow Customer', amount: 300.0, memo: 'Approval flow test', push_to_acumatica: false);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'invoice', target_id: (int) $created['invoice_id']);

        $this->assertFalse($result['approved']);
        $this->assertSame('not_authorized', $result['reason']);
    }

    public function test_approve_pending_item_reports_no_approver_configured_when_the_customer_has_none(): void
    {
        $this->seedTestOrganization('Approval Flow Customer 1B');

        $created = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Approval Flow Customer 1B', amount: 300.0, memo: 'Approval flow test', push_to_acumatica: false);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'invoice', target_id: (int) $created['invoice_id']);

        $this->assertFalse($result['approved']);
        $this->assertSame('no_approver_configured', $result['reason']);
    }

    public function test_approve_pending_item_approves_a_pending_invoice_and_carries_the_source_email(): void
    {
        $customer = $this->seedTestOrganization('Approval Flow Customer 2');
        $customer->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, static::$cachedUser->email);

        $created = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                customer_name: 'Approval Flow Customer 2',
                amount: 300.0,
                memo: 'Approval flow test',
                push_to_acumatica: false,
                source_email_message_id: 'MSG_AR_APR_1',
                source_attachment_url: 'https://cdn.example.test/invoice-ar-apr-1.pdf',
                source_attachment_filename: 'invoice-ar-apr-1.pdf',
            );

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'invoice', target_id: (int) $created['invoice_id']);

        $this->assertTrue($result['approved']);
        $this->assertSame('MSG_AR_APR_1', $result['source_email_message_id']);
        $this->assertSame('https://cdn.example.test/invoice-ar-apr-1.pdf', $result['source_attachment_url']);
        $this->assertSame('invoice-ar-apr-1.pdf', $result['source_attachment_filename']);
        $this->assertSame(static::$cachedUser->email, $result['approved_by']);
        $this->assertNotEmpty($result['approved_at']);

        $invoice = Invoice::query()->where('id', $created['invoice_id'])->first();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
    }

    public function test_approve_pending_item_reports_not_found_when_nothing_pending(): void
    {
        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'invoice', target_id: 999999999);

        $this->assertFalse($result['approved']);
        $this->assertSame('not_found', $result['reason']);
    }

    public function test_approve_pending_item_authorizes_the_conversation_human_not_the_agents_own_identity(): void
    {
        $customer = $this->seedTestOrganization('Approval Flow Customer 3');
        $customer->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, static::$cachedUser->email);

        $created = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Approval Flow Customer 3', amount: 300.0, memo: 'Approval flow test', push_to_acumatica: false);

        // Mirrors an @mention/channel turn: setConfiguration() receives the agent's OWN user, distinct
        // from the human actually approving, exactly like SlackUserResolverService resolving a DM sender.
        $agentOwnUser = Users::factory()->create(['email' => 'agent-own-user-' . uniqid() . '@internal.test']);
        $agentType = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['provider' => 'neuron']);
        $agentModel = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'user_id' => $agentOwnUser->getId()]);

        $handler = new AccountsReceivableAgent();
        $handler->setConfiguration($agentModel, user: $agentOwnUser);
        $handler->setConversationHuman(static::$cachedUser);

        $approveTool = null;
        foreach ($handler->getTools() as $tool) {
            if ($tool instanceof ApprovePendingItemTool) {
                $approveTool = $tool;

                break;
            }
        }

        $this->assertNotNull($approveTool, 'approve_pending_item must be registered once the conversation human is known.');

        $result = $approveTool->__invoke(target_type: 'invoice', target_id: (int) $created['invoice_id']);

        $this->assertTrue($result['approved'], 'The configured approver must be authorized even when the agent turn is wired with its own identity.');
        $this->assertSame(static::$cachedUser->email, $result['approved_by']);
    }

    public function test_match_invoices_for_payment_flags_the_exact_invoice(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');

        Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-9001',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 1200.0, 'total_native' => 1200.0, 'paid_native' => 0.0, 'balance_due_native' => 1200.0,
            'subtotal_base' => 1200.0, 'total_base' => 1200.0, 'paid_base' => 0.0, 'balance_due_base' => 1200.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $result = new MatchInvoicesForPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer: 'Acme Corp', amount: 1200.0);

        $this->assertNotEmpty($result['open_invoices']);
        $this->assertSame('INV-9001', $result['exact_match']);
    }

    public function test_create_ar_credit_memo_requires_a_customer_name(): void
    {
        $result = new CreateArCreditMemoTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: '', invoice_number: 'REF-1', lines: [['control_account_number' => '71610', 'amount' => 50.0]]);

        $this->assertFalse($result['created']);
        $this->assertSame('customer_name_required', $result['reason']);
    }

    public function test_create_ar_credit_memo_requires_an_invoice_number(): void
    {
        $customer = $this->seedTestOrganization('Proshop');

        $result = new CreateArCreditMemoTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Proshop', invoice_number: '', lines: [['control_account_number' => '71610', 'amount' => 50.0]]);

        $this->assertFalse($result['created']);
        $this->assertSame('invoice_number_required', $result['reason']);
    }

    public function test_create_ar_credit_memo_reports_not_found_for_unknown_customer(): void
    {
        $result = new CreateArCreditMemoTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Does Not Exist Inc', invoice_number: 'REF-1', lines: [['control_account_number' => '71610', 'amount' => 50.0]]);

        $this->assertFalse($result['created']);
        $this->assertSame('customer_not_found', $result['reason']);
    }

    public function test_create_ar_credit_memo_reports_not_found_for_unknown_control_account(): void
    {
        $this->seedTestOrganization('Proshop');

        $result = new CreateArCreditMemoTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Proshop', invoice_number: 'REF-1', lines: [['control_account_number' => 'DOES-NOT-EXIST', 'amount' => 50.0]]);

        $this->assertFalse($result['created']);
        $this->assertSame('account_not_found', $result['reason']);
    }

    public function test_create_ar_credit_memo_issues_a_standalone_credit_note(): void
    {
        $customer = $this->seedTestOrganization('Proshop Rebate QA Customer');
        $controlAccount = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::TRAVEL_AND_MEALS->value)
            ->firstOrFail();

        $result = new CreateArCreditMemoTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                customer_name: 'Proshop Rebate QA Customer',
                invoice_number: 'Proshop Superdays Sell-Out (22/05-07/06)',
                lines: [
                    ['control_account_number' => $controlAccount->account_number, 'amount' => 250.0, 'description' => 'Promotion Discount'],
                ],
            );

        $this->assertTrue($result['created']);
        $this->assertSame('Proshop Rebate QA Customer', $result['customer']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['processed_at']);

        /** @var Invoice $creditNote */
        $creditNote = Invoice::query()->where('id', $result['credit_memo_id'])->firstOrFail();
        $this->assertSame(DocumentTypeEnum::CREDIT_NOTE, $creditNote->document_type);
        $this->assertNull($creditNote->parent_invoice_id);
        $this->assertSame('Proshop Superdays Sell-Out (22/05-07/06)', $creditNote->invoice_number);
        $this->assertSame($customer->getId(), $creditNote->customer_organization_id);
        $this->assertSame(250.0, (float) $creditNote->total_native);

        $line = $creditNote->lines->first();
        $this->assertSame($controlAccount->getId(), $line->account_id);
    }

    public function test_create_ar_credit_memo_notifies_the_configured_default_email(): void
    {
        $originalNotificationEmail = $this->kanvasApp->get(InvoicesConfigurationEnum::CREDIT_MEMO_NOTIFICATION_EMAIL->value);
        $originalNotifierAgentId = $this->kanvasApp->get(InvoicesConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value);

        try {
            $notifierAgent = Agent::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($this->company->getId())
                ->create(['name' => 'Apex', 'user_id' => static::$cachedUser->getId()]);
            $notifierAgent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');

            $this->kanvasApp->set(InvoicesConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value, (string) $notifierAgent->getId());
            $this->kanvasApp->set(InvoicesConfigurationEnum::CREDIT_MEMO_NOTIFICATION_EMAIL->value, 'notify@example.test');

            Http::fake([
                'slack.com/api/users.lookupByEmail' => Http::response(['ok' => true, 'user' => ['id' => 'U123']]),
                'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
                'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
            ]);

            $customer = $this->seedTestOrganization('Notification Test Customer');
            $controlAccount = Account::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('companies_id', $this->company->getId())
                ->where('account_sub_type', AccountSubTypeEnum::TRAVEL_AND_MEALS->value)
                ->firstOrFail();

            $result = new CreateArCreditMemoTool()
                ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
                ->__invoke(
                    customer_name: 'Notification Test Customer',
                    invoice_number: 'Notification Test Reference',
                    lines: [
                        ['control_account_number' => $controlAccount->account_number, 'amount' => 75.0],
                    ],
                );

            $this->assertTrue($result['created']);
            Http::assertSent(
                fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
                    && str_contains((string) $request['text'], 'Notification Test Customer')
                    && str_contains((string) $request['text'], (string) $result['credit_memo_id'])
            );
        } finally {
            $this->kanvasApp->set(InvoicesConfigurationEnum::CREDIT_MEMO_NOTIFICATION_EMAIL->value, $originalNotificationEmail);
            $this->kanvasApp->set(InvoicesConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value, $originalNotifierAgentId);
        }
    }

    public function test_add_invoice_note_reports_not_found_for_unknown_invoice(): void
    {
        $result = new AddInvoiceNoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: 999999999, note: 'Called customer.');

        $this->assertFalse($result['note_added']);
        $this->assertSame('invoice_not_found', $result['reason']);
    }

    public function test_attach_invoice_file_reports_not_found_for_unknown_invoice(): void
    {
        $result = new AttachInvoiceFileTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: 999999999, file_url: 'https://example.test/credit.xlsx');

        $this->assertFalse($result['file_attached']);
        $this->assertSame('invoice_not_found', $result['reason']);
    }

    public function test_find_customer_returns_a_dead_end_message_when_nothing_matches(): void
    {
        // A bare count=0 reads as "try again" to the model, which is how the same name got re-queried
        // until the run budget tripped (Sentry KANVAS-ECOSYSTEM-64Q).
        $result = new FindCustomerTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(name: 'Nonexistent Customer ' . uniqid());

        $this->assertSame(0, (int) $result['count']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('Repeating this exact call will not find anything', $result['note']);
    }

    /**
     * AR staff drive these per-record tools once per row over a spreadsheet/remittance, so each must key
     * its run budget by inputs — otherwise the 11th DISTINCT call in a turn trips NeuronAI's per-tool-name
     * cap and aborts the whole turn (Sentry KANVAS-ECOSYSTEM-64Q, seen on find_customer mid-Excel).
     */
    public function test_ar_per_record_tools_key_their_run_budget_by_inputs(): void
    {
        $tools = [
            new FindCustomerTool(),
            new FindInvoiceTool(),
            new MatchInvoicesForPaymentTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool->getName() . ' must key its run budget by inputs.');

            $tool->setInputs(['name' => 'Industrias San Miguel', 'customer' => 'Industrias San Miguel', 'invoice_number' => 'INV-1']);
            $keyOne = $tool->getRunKey();

            $tool->setInputs(['name' => 'Acme Corporation', 'customer' => 'Acme Corporation', 'invoice_number' => 'INV-2']);
            $keyTwo = $tool->getRunKey();

            $tool->setInputs(['name' => 'Industrias San Miguel', 'customer' => 'Industrias San Miguel', 'invoice_number' => 'INV-1']);
            $keyOneAgain = $tool->getRunKey();

            $this->assertNotEquals($keyOne, $keyTwo, $tool->getName() . ': distinct records must not share a run budget.');
            $this->assertEquals($keyOneAgain, $keyOne, $tool->getName() . ': identical calls must collapse so a loop is still capped.');
        }
    }
}
