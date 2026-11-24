<?php

declare(strict_types=1);

namespace Tests\Scribe;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ElectronicInvoicing\EcfTestCase;

/**
 * Payment runs, the daily digest and the vendor e-CF tools: a fresh company per test (EcfTestCase), so
 * settings written to Redis and the company-wide counts the digest reads never see another test's rows.
 */
abstract class AccountingOperationsTestCase extends EcfTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();
        Notification::fake();
    }

    protected function companyIssuesEcf(): bool
    {
        return false;
    }

    protected function receivedBill(
        string $vendorName,
        float $amount,
        string $currency = 'USD',
        string $dueDate = '2026-06-20'
    ): Bill {
        $vendor = $this->seedTestOrganization($vendorName . ' ' . uniqid());

        $draft = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Service',
                        quantity: 1,
                        unit_price_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES),
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse($dueDate),
            ),
            user: static::$cachedUser,
        )->execute();

        return new ReceiveBillAction(
            bill: $draft,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();
    }

    protected function bankAccount(string $currency, ?float $balance, string $name = 'Popular'): BankAccount
    {
        $account = new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: $name . ' ' . $currency,
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
                currency: $currency,
                account_number_last4: (string) random_int(1000, 9999),
                institution_name: 'Banco Popular',
                source: 'kanvas',
            ),
            user: static::$cachedUser,
        )->execute();

        if ($balance !== null) {
            $account->current_balance_native = $balance;
            $account->last_balance_sync_at = Carbon::now();
            $account->save();
        }

        return $account;
    }

    protected function accountingAgent(string $handler, ?Users $creator = null): Agent
    {
        $type = AgentType::query()->where('handler', $handler)->first()
            ?? AgentType::factory()->withAppId($this->kanvasApp->getId())->create([
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'is_active' => true,
                'created_by_users_id' => $creator?->getId(),
            ]);
    }
}
