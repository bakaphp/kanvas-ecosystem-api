<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchBillsForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryApAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AddBillNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AttachBillFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateApBillTool;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class AccountsPayableAgentToolsTest extends ScribeTestCase
{
    private function receiveOpenBill(Organization $vendor, float $amount, string $dueDate): void
    {
        $bill = new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Materials',
                        quantity: 1,
                        unit_price_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse($dueDate),
            ),
            static::$cachedUser,
        )->execute();

        new ReceiveBillAction($bill, $vendor, static::$cachedUser)->execute();
    }

    public function test_match_bills_for_payment_flags_the_exact_bill(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 800.0, '2026-06-20');

        $result = new MatchBillsForPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(vendor: 'Globex Supply', amount: 800.0);

        $this->assertNotEmpty($result['open_bills']);
        $this->assertSame(800.0, (float) $result['total_open']);
        $this->assertNotNull($result['exact_match']);
    }

    public function test_ap_aging_and_open_bills_tools_report_owed_amounts(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 800.0, '2026-05-20'); // overdue

        $aging = new QueryApAgingTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(as_of: '2026-06-01');
        $this->assertGreaterThanOrEqual(800.0, (float) $aging['grand_total']);
        $this->assertGreaterThanOrEqual(1, (int) $aging['vendor_count']);

        $open = new ListOpenBillsTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke();
        $this->assertGreaterThanOrEqual(1, (int) $open['count']);
        $this->assertGreaterThanOrEqual(800.0, (float) $open['total_balance_due']);
    }

    public function test_list_open_purchase_orders_tool_returns_open_pos(): void
    {
        $po = PurchaseOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'order_type' => 'RO',
            'order_number' => 'PO900',
            'vendor_code' => 'V0000505',
            'status' => 'N',
            'currency' => 'USD',
            'order_total' => 1000,
            'source' => 'acumatica',
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'line_number' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Cooler',
            'open_qty' => 5,
            'unit_cost' => 200,
        ]);

        $result = new ListOpenPurchaseOrdersTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(vendor_code: 'V0000505');

        $this->assertSame(1, (int) $result['count']);
        $this->assertSame('PO900', $result['purchase_orders'][0]['order_number']);
        $this->assertSame(1, (int) $result['purchase_orders'][0]['open_line_count']);
    }

    public function test_find_vendor_tool_returns_acumatica_code(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');

        $result = new FindVendorTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(name: 'Globex Supply Co');

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $codes = array_column($result['vendors'], 'acumatica_vendor_code');
        $this->assertContains('V0000505', $codes);
    }

    public function test_find_purchase_order_returns_full_detail_or_not_found(): void
    {
        $po = PurchaseOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'order_type' => 'RO',
            'order_number' => 'PO4242',
            'vendor_code' => 'V0000505',
            'status' => 'N',
            'currency' => 'USD',
            'order_total' => 1200,
            'source' => 'acumatica',
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'line_number' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Cooler',
            'open_qty' => 6,
            'unit_cost' => 200,
        ]);

        $found = new FindPurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(order_number: 'PO4242');
        $this->assertTrue($found['found']);
        $this->assertSame('V0000505', $found['vendor_code']);
        $this->assertCount(1, $found['lines']);
        $this->assertSame('RL-KP336', $found['lines'][0]['sku']);

        $missing = new FindPurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(order_number: 'DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }

    public function test_find_bill_returns_full_detail(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 640.0, '2026-06-20');

        $bill = \Kanvas\Scribe\Bills\Models\Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $result = new FindBillTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(bill_number: (string) $bill->bill_number);

        $this->assertTrue($result['found']);
        $this->assertSame('received', $result['document_status']);
        $this->assertSame(640.0, (float) $result['balance_due_native']);
        $this->assertNotEmpty($result['lines']);
    }

    public function test_add_bill_note_reports_not_found_for_unknown_bill(): void
    {
        $result = new AddBillNoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(bill_id: 999999999, note: 'Called vendor.');

        $this->assertFalse($result['note_added']);
        $this->assertSame('bill_not_found', $result['reason']);
    }

    public function test_add_bill_note_reports_not_pushed_when_bill_has_no_acumatica_ref(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 300.0, '2026-06-20');

        $bill = \Kanvas\Scribe\Bills\Models\Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $result = new AddBillNoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(bill_id: (int) $bill->id, note: 'Called vendor.');

        $this->assertFalse($result['note_added']);
        $this->assertSame('bill_not_pushed', $result['reason']);
    }

    public function test_attach_bill_file_reports_not_found_for_unknown_bill(): void
    {
        $result = new AttachBillFileTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(bill_id: 999999999, file_url: 'https://example.test/invoice.pdf');

        $this->assertFalse($result['file_attached']);
        $this->assertSame('bill_not_found', $result['reason']);
    }

    public function test_attach_bill_file_reports_not_pushed_when_bill_has_no_acumatica_ref(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 300.0, '2026-06-20');

        $bill = \Kanvas\Scribe\Bills\Models\Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $result = new AttachBillFileTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(bill_id: (int) $bill->id, file_url: 'https://example.test/invoice.pdf');

        $this->assertFalse($result['file_attached']);
        $this->assertSame('bill_not_pushed', $result['reason']);
    }

    public function test_create_ap_bill_requires_an_invoice_number(): void
    {
        $this->seedTestOrganization('Windwalk Games Corp');
        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 2500.0,
                gl_account_number: $accountCode,
                memo: 'Community Building & Management Services',
                invoice_number: '',
            );

        $this->assertFalse($result['created']);
        $this->assertSame('invoice_number_required', $result['reason']);
    }

    public function test_create_ap_bill_uses_the_vendor_invoice_number_and_resolves_subaccount(): void
    {
        $this->seedTestOrganization('Windwalk Games Corp');
        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $accountCode = (string) Account::query()->where('id', $accountId)->value('account_number');

        new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 2500.0,
                gl_account_number: $accountCode,
                memo: 'Community Building & Management Services',
                invoice_number: '1498',
                subaccount: 'BB-0G-M1',
            );

        /** @var Bill $bill */
        $bill = Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $this->assertSame('1498', $bill->bill_number);
        $this->assertSame('BB-0G-M1', $bill->lines->first()->subaccount->sub_code);
    }

    public function test_create_ap_bill_with_push_to_acumatica_false_stops_at_pending_approval(): void
    {
        $this->seedTestOrganization('Windwalk Games Corp');
        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $accountCode = (string) Account::query()->where('id', $accountId)->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 750.0,
                gl_account_number: $accountCode,
                memo: 'Pending flow test',
                invoice_number: 'PEND-1',
                push_to_acumatica: false,
            );

        $this->assertTrue($result['created']);
        $this->assertFalse($result['pushed']);
        $this->assertSame('pending_approval', $result['document_status']);
        $this->assertArrayNotHasKey('bill_ref', $result);
        $this->assertArrayNotHasKey('acumatica_bill_id', $result);

        $bill = Bill::query()->where('id', $result['bill_id'])->first();
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);
    }

    public function test_create_ap_bill_reports_the_approver_sheets_vendor_spelling_not_the_organizations_own_name(): void
    {
        $vendor = $this->seedTestOrganization('GmbH-PENNER + PARTNER GBR');
        $vendor->set(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, 'Penner + Partner WP StB mbB');
        $vendor->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'fanny.peng@example.test');

        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Penner + Partner WP StB mbB',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'Display name test',
                invoice_number: 'DISP-1',
                push_to_acumatica: false,
            );

        $this->assertTrue($result['created']);
        $this->assertSame('Penner + Partner WP StB mbB', $result['vendor']);
        $this->assertSame('', $result['approved_by_flag']);
    }

    public function test_create_ap_bill_flags_the_sheet_row_when_the_vendor_has_no_approver_configured(): void
    {
        $this->seedTestOrganization('No Approver Vendor Corp');

        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'No Approver Vendor Corp',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'No approver test',
                invoice_number: 'NOAPP-1',
                push_to_acumatica: false,
            );

        $this->assertTrue($result['created']);
        $this->assertSame('NOT IN APPROVER LIST', $result['approved_by_flag']);
    }

    public function test_create_ap_bill_treats_an_explicit_null_push_flag_as_the_default(): void
    {
        // The LLM sends `"push_to_acumatica": null` for an omitted optional boolean, which used to
        // TypeError against a non-nullable `bool $push_to_acumatica = true` (Sentry KANVAS-ECOSYSTEM-67Z).
        $this->seedTestOrganization('Windwalk Games Corp');
        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $accountCode = (string) Account::query()->where('id', $accountId)->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 2500.0,
                gl_account_number: $accountCode,
                memo: 'Null push flag test',
                invoice_number: 'NULLFLAG-1',
                push_to_acumatica: null,
            );

        $this->assertTrue($result['created']);
        $this->assertNotSame('pending_approval', $result['document_status']);
    }

    public function test_approve_pending_item_requires_the_configured_approver(): void
    {
        $vendor = $this->seedTestOrganization('Windwalk Games Corp');
        $vendor->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'someone-else-' . uniqid() . '@example.test');

        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $created = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'Approval flow test',
                invoice_number: 'APR-1',
                push_to_acumatica: false,
            );

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'bill', target_id: (int) $created['bill_id']);

        $this->assertFalse($result['approved']);
        $this->assertSame('not_authorized', $result['reason']);
    }

    public function test_approve_pending_item_reports_no_approver_configured_when_the_vendor_has_none(): void
    {
        $this->seedTestOrganization('Windwalk Games Corp');
        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $created = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'Approval flow test',
                invoice_number: 'APR-1B',
                push_to_acumatica: false,
            );

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'bill', target_id: (int) $created['bill_id']);

        $this->assertFalse($result['approved']);
        $this->assertSame('no_approver_configured', $result['reason']);
    }

    public function test_approve_pending_item_approves_a_pending_bill_and_carries_the_source_email(): void
    {
        $vendor = $this->seedTestOrganization('Windwalk Games Corp');
        $vendor->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, static::$cachedUser->email);

        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $created = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'Approval flow test',
                invoice_number: 'APR-2',
                push_to_acumatica: false,
                source_email_message_id: 'MSG_APR_2',
                source_attachment_url: 'https://cdn.example.test/invoice-apr-2.pdf',
                source_attachment_filename: 'invoice-apr-2.pdf',
            );

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'bill', target_id: (int) $created['bill_id']);

        $this->assertTrue($result['approved']);
        $this->assertSame('MSG_APR_2', $result['source_email_message_id']);
        $this->assertSame('https://cdn.example.test/invoice-apr-2.pdf', $result['source_attachment_url']);
        $this->assertSame('invoice-apr-2.pdf', $result['source_attachment_filename']);
        $this->assertSame(static::$cachedUser->email, $result['approved_by']);
        $this->assertNotEmpty($result['approved_at']);

        $bill = Bill::query()->where('id', $created['bill_id'])->first();
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->document_status);
    }

    public function test_approve_pending_item_reports_not_found_when_nothing_pending(): void
    {
        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'bill', target_id: 999999999);

        $this->assertFalse($result['approved']);
        $this->assertSame('not_found', $result['reason']);
    }

    public function test_approve_pending_item_authorizes_the_conversation_human_not_the_agents_own_identity(): void
    {
        $vendor = $this->seedTestOrganization('Windwalk Games Corp');
        $vendor->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, static::$cachedUser->email);

        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $created = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: 'Windwalk Games Corp',
                amount: 500.0,
                gl_account_number: $accountCode,
                memo: 'Approval flow test',
                invoice_number: 'APR-3',
                push_to_acumatica: false,
            );

        // Mirrors an @mention/channel turn: setConfiguration() receives the agent's OWN user, distinct
        // from the human actually approving, exactly like SlackUserResolverService resolving a DM sender.
        $agentOwnUser = Users::factory()->create(['email' => 'agent-own-user-' . uniqid() . '@internal.test']);
        $agentType = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['provider' => 'neuron']);
        $agentModel = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'user_id' => $agentOwnUser->getId()]);

        $handler = new AccountsPayableAgent();
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

        $result = $approveTool->__invoke(target_type: 'bill', target_id: (int) $created['bill_id']);

        $this->assertTrue($result['approved'], 'The configured approver must be authorized even when the agent turn is wired with its own identity.');
        $this->assertSame(static::$cachedUser->email, $result['approved_by']);
    }

    public function test_find_vendor_returns_a_dead_end_message_when_nothing_matches(): void
    {
        // A bare count=0 reads as "try again" to the model, which is how the same name got re-queried
        // until the run budget tripped (Sentry KANVAS-ECOSYSTEM-64Q).
        $result = new FindVendorTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(name: 'Nonexistent Vendor ' . uniqid());

        $this->assertSame(0, (int) $result['count']);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Retrying the same name will not help', $result['message']);
    }

    /**
     * AP staff drive these per-record tools once per row over an invoice batch/remittance, so each must key
     * its run budget by inputs — otherwise the 11th DISTINCT call in a turn trips NeuronAI's per-tool-name
     * cap and aborts the whole turn (Sentry KANVAS-ECOSYSTEM-64Q).
     */
    public function test_ap_per_record_tools_key_their_run_budget_by_inputs(): void
    {
        $tools = [
            new FindVendorTool(),
            new FindBillTool(),
            new FindPurchaseOrderTool(),
            new MatchBillsForPaymentTool(),
            new CreateApBillTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool->getName() . ' must key its run budget by inputs.');

            $tool->setInputs(['name' => 'Globex Supply', 'vendor' => 'Globex Supply', 'bill_number' => 'B-1', 'order_number' => 'PO-1']);
            $keyOne = $tool->getRunKey();

            $tool->setInputs(['name' => 'Initech', 'vendor' => 'Initech', 'bill_number' => 'B-2', 'order_number' => 'PO-2']);
            $keyTwo = $tool->getRunKey();

            $tool->setInputs(['name' => 'Globex Supply', 'vendor' => 'Globex Supply', 'bill_number' => 'B-1', 'order_number' => 'PO-1']);
            $keyOneAgain = $tool->getRunKey();

            $this->assertNotEquals($keyOne, $keyTwo, $tool->getName() . ': distinct records must not share a run budget.');
            $this->assertEquals($keyOneAgain, $keyOne, $tool->getName() . ': identical calls must collapse so a loop is still capped.');
        }
    }
}
