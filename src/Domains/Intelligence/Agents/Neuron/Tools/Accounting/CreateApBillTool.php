<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum as AcumaticaConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Models\Account;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Creates a one-line AP bill, auto-approves it (acting as the agent's own user), and pushes it to
 * Acumatica synchronously — returning the ERP bill reference in the same call.
 *
 * This bypasses the normal human-in-the-loop gate (draft -> pending_approval -> a person approves)
 * on purpose, so it is HARD-GATED to a tenant explicitly marked as staging: the app's
 * ACUMATICA_ENVIRONMENT config must equal exactly 'staging', checked FIRST, before anything is
 * created in Kanvas. Nothing about the connection itself (base URL, credentials) is trusted to imply
 * this — it must be a deliberate, manually-set flag. If it isn't set, or is anything else, this tool
 * refuses to run and creates nothing.
 *
 * @see \Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction — still recorded in the approval queue
 *      for traceability even though approval here is automatic, not human.
 * @see \Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction — the actual outbound push.
 */
#[AgentTool(name: 'Create AP Bill')]
class CreateApBillTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ap_bill',
            description: 'STAGING ONLY. Creates a one-line AP bill, auto-approves it, and pushes it to Acumatica '
                . 'in one step, returning the Acumatica bill reference. Only works when this app is explicitly '
                . 'configured as a staging tenant — otherwise it refuses and creates nothing. Use only for '
                . 'deliberate write-path testing, never to record a real vendor bill.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The bill amount, e.g. 1.00.',
                required: true,
            ),
            new ToolProperty(
                name: 'gl_account_number',
                type: PropertyType::STRING,
                description: 'The GL expense account number to code the line to, e.g. "71610".',
                required: true,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Description / memo for the bill and its single line, e.g. "TEST write-path".',
                required: true,
            ),
            new ToolProperty(
                name: 'vendor_name',
                type: PropertyType::STRING,
                description: 'Vendor name to match (substring). Omit to use any existing active vendor '
                    . 'organization on this app/company — fine for a write-path smoke test.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        float $amount,
        string $gl_account_number,
        string $memo,
        ?string $vendor_name = null,
        ?string $currency = null,
    ): array {
        $app = $this->app;
        $company = $this->company;

        $environment = (string) $app->get(AcumaticaConfigurationEnum::ACUMATICA_ENVIRONMENT->value, '');

        if ($environment !== 'staging') {
            return [
                'created' => false,
                'reason' => 'not_staging',
                'message' => 'This app is not marked as an Acumatica staging tenant '
                    . '(ACUMATICA_ENVIRONMENT must equal "staging") — refusing to create or push anything.',
            ];
        }

        $vendorQuery = Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false);

        if ($vendor_name !== null && trim($vendor_name) !== '') {
            $vendorQuery->where('name', 'like', '%' . trim($vendor_name) . '%');
        }

        $vendor = $vendorQuery->first();

        if ($vendor === null) {
            return [
                'created' => false,
                'reason' => 'vendor_not_found',
                'message' => $vendor_name !== null
                    ? "No vendor organization matching \"{$vendor_name}\" for this app/company."
                    : 'No active vendor organization exists for this app/company yet.',
            ];
        }

        $account = Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_number', $gl_account_number)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return [
                'created' => false,
                'reason' => 'account_not_found',
                'message' => "No active GL account with number \"{$gl_account_number}\" for this app/company.",
            ];
        }

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;

        $bill = new CreateBillAction(
            new BillData(
                app: $app,
                company: $company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: $memo,
                        quantity: 1.0,
                        unit_price_native: $amount,
                        expense_account_id: $account->getId(),
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
                bill_date: Carbon::today(),
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        new SubmitBillForApprovalAction($bill, $actingUser)->execute();

        /** @var Organization $approvalVendor */
        $approvalVendor = Organization::query()->where('id', $bill->vendor_organization_id)->first();

        $bill = new ApproveBillAction($bill, $approvalVendor, $actingUser)->execute();

        try {
            $reference = new PushBillToAcumaticaAction($bill)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'bill_number' => $bill->bill_number,
                'document_status' => $bill->document_status->value,
                'reason' => 'push_failed',
                'message' => 'Bill was created and approved in Kanvas (status: received) but the push to '
                    . 'Acumatica failed: ' . $e->getMessage() . '. It needs manual attention — it will not '
                    . 'auto-retry.',
            ];
        }

        return [
            'created' => true,
            'pushed' => true,
            'bill_id' => $bill->getId(),
            'bill_number' => $bill->bill_number,
            'document_status' => $bill->document_status->value,
            'vendor' => $vendor->name,
            'amount' => $amount,
            'currency' => $currency,
            'gl_account' => $gl_account_number,
            'memo' => $memo,
            'bill_ref' => $reference,
            'acumatica_bill_id' => (string) $bill->get(AcumaticaCustomFieldEnum::BILL_ID->value, ''),
            'next' => 'Pushed to Acumatica staging. bill_ref is the ERP reference — use it to find and void the '
                . 'test record when done.',
        ];
    }
}
