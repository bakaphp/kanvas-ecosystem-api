<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/** Policy, approver and submitted-bill fixtures shared by the Scribe approval tests. Use from a ScribeTestCase. */
trait SeedsBillApprovals
{
    protected function seedBillPolicy(): ApprovalPolicy
    {
        return $this->seedPolicy(
            Bill::class,
            'approve_bill',
            'vendor',
            ApproveAndPushBillHandler::class
        );
    }

    protected function seedPolicy(
        string $model,
        string $approvalType,
        string $relation,
        string $handler,
        ?string $fallbackResolver = null,
        bool $allowAuthorityOverride = false,
    ): ApprovalPolicy {
        return ApprovalPolicy::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'system_modules_id' => SystemModulesRepository::getByModelName($model, $this->kanvasApp)->getId(),
            'approval_type' => $approvalType,
            'steps' => [[
                'step' => 1,
                'resolver' => 'organization_approver',
                'config' => ['relation' => $relation],
                'required_approvals' => 1,
            ]],
            'handler' => $handler,
            'fallback_resolver' => $fallbackResolver,
            'allow_authority_override' => $allowAuthorityOverride,
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);
    }

    protected function linkApprover(Organization $vendor, string $prefix = 'ap-approver'): Users
    {
        $user = Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);
        new AddApproverToOrganizationAction($vendor, $user)->execute();

        return $user;
    }

    protected function submitBill(Organization $vendor): Bill
    {
        $bill = new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Raw materials',
                        quantity: 1,
                        unit_price_native: 500.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-15'),
            ),
            static::$cachedUser,
        )->execute();

        return new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();
    }
}
