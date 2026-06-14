<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * Seeds the standard Chart of Accounts on company creation.
 *
 * - US-default COA (always applied).
 * - DR extension (applied when country_code='DO') — ITBIS Payable, ISC Payable, ISR Payable + other DR-specific accounts.
 * - Idempotent: re-running on an already-seeded company is a no-op (skips any account_number that exists).
 *
 * Account numbering follows the standard 4-digit ranges used by QBO/NetSuite/Xero:
 *   1xxx Assets
 *   2xxx Liabilities
 *   3xxx Equity
 *   4xxx Revenue
 *   5xxx COGS
 *   6xxx Operating Expenses
 *   7xxx Other Income
 *   8xxx Other Expense
 *
 * System accounts (is_system=true) are the ones JE composers look up by well-known sub_type to compose JEs.
 * They cannot be deleted by tenants. Tenants can rename them (the `name` field) but the account_number + sub_type
 * are fixed.
 */
class ChartOfAccountsSeederService
{
    public function seedUsDefault(int $appsId, int $companiesId, ?string $defaultCurrency = 'USD', ?int $userId = null): int
    {
        return $this->seed($appsId, $companiesId, $defaultCurrency, $userId, $this->usDefaultAccounts());
    }

    public function seedDominicanRepublicDefault(int $appsId, int $companiesId, ?int $userId = null): int
    {
        return $this->seed(
            $appsId,
            $companiesId,
            'DOP',
            $userId,
            array_merge($this->usDefaultAccounts(), $this->dominicanRepublicAccounts()),
        );
    }

    /**
     * Country-aware entry point. Picks the right COA set based on the company's country_code config.
     *
     * @param string|null $countryCode 'DO' for Dominican Republic, anything else (or null) falls back to US-default.
     */
    public function seedForCountry(int $appsId, int $companiesId, ?string $countryCode = null, ?int $userId = null): int
    {
        return match (strtoupper((string) $countryCode)) {
            'DO' => $this->seedDominicanRepublicDefault($appsId, $companiesId, $userId),
            default => $this->seedUsDefault($appsId, $companiesId, defaultCurrency: 'USD', userId: $userId),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @return int Number of new accounts inserted (skipped existing rows are not counted).
     */
    private function seed(int $appsId, int $companiesId, ?string $defaultCurrency, ?int $userId, array $accounts): int
    {
        $existingNumbers = Account::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->pluck('account_number')
            ->all();

        $inserted = 0;
        DB::connection('accounting')->transaction(function () use ($accounts, $existingNumbers, $appsId, $companiesId, $defaultCurrency, $userId, &$inserted) {
            foreach ($accounts as $row) {
                if (in_array($row['account_number'], $existingNumbers, true)) {
                    continue;
                }

                $account = new Account();
                $account->apps_id = $appsId;
                $account->companies_id = $companiesId;
                $account->uuid = (string) Str::uuid();
                $account->account_number = $row['account_number'];
                $account->name = $row['name'];
                $account->description = $row['description'] ?? null;
                $account->account_type = AccountTypeEnum::from($row['account_type']);
                $account->account_sub_type = $row['account_sub_type'] ?? null;
                $account->currency = $row['currency'] ?? $defaultCurrency;
                $account->is_active = true;
                $account->is_system = (bool) ($row['is_system'] ?? false);
                $account->source = 'kanvas';
                $account->users_id = $userId;
                $account->save();
                $inserted++;
            }
        });

        return $inserted;
    }

    /**
     * Standard US-default Chart of Accounts.
     *
     * Sub-type values follow QBO's granularity for cash + AR + AP + system accounts so the JE composers
     * (and the agent) can answer questions like "liquid cash vs total cash" or "trade AR vs other AR".
     */
    private function usDefaultAccounts(): array
    {
        return [
            // === ASSETS (1xxx) ===
            ['account_number' => '1000', 'name' => 'Cash — Checking',       'account_type' => 'asset', 'account_sub_type' => 'cash_checking',          'is_system' => true],
            ['account_number' => '1010', 'name' => 'Cash — Savings',        'account_type' => 'asset', 'account_sub_type' => 'cash_savings'],
            ['account_number' => '1020', 'name' => 'Cash — Money Market',   'account_type' => 'asset', 'account_sub_type' => 'cash_money_market'],
            ['account_number' => '1030', 'name' => 'Cash on Hand',          'account_type' => 'asset', 'account_sub_type' => 'cash_on_hand'],
            ['account_number' => '1100', 'name' => 'Accounts Receivable',   'account_type' => 'asset', 'account_sub_type' => 'accounts_receivable',     'is_system' => true],
            ['account_number' => '1200', 'name' => 'Vendor Prepayments',    'account_type' => 'asset', 'account_sub_type' => 'vendor_prepayments',      'is_system' => true],
            ['account_number' => '1300', 'name' => 'Inventory Asset',       'account_type' => 'asset', 'account_sub_type' => 'inventory_asset'],
            ['account_number' => '1400', 'name' => 'Prepaid Expenses',      'account_type' => 'asset', 'account_sub_type' => 'prepaid_expenses'],
            ['account_number' => '1500', 'name' => 'Undeposited Funds',     'account_type' => 'asset', 'account_sub_type' => 'undeposited_funds'],

            // === LIABILITIES (2xxx) ===
            ['account_number' => '2000', 'name' => 'Accounts Payable',         'account_type' => 'liability', 'account_sub_type' => 'accounts_payable',         'is_system' => true],
            ['account_number' => '2100', 'name' => 'Credit Card Liability',    'account_type' => 'liability', 'account_sub_type' => 'credit_card_liability',    'is_system' => true],
            ['account_number' => '2200', 'name' => 'Sales Tax Payable',        'account_type' => 'liability', 'account_sub_type' => 'sales_tax_payable',        'is_system' => true],
            ['account_number' => '2300', 'name' => 'Customer Prepayments',     'account_type' => 'liability', 'account_sub_type' => 'customer_prepayments',     'is_system' => true],
            ['account_number' => '2400', 'name' => 'Customer Overpayments',    'account_type' => 'liability', 'account_sub_type' => 'customer_overpayments',    'is_system' => true],
            ['account_number' => '2500', 'name' => 'Due to Employees',         'account_type' => 'liability', 'account_sub_type' => 'due_to_employees',         'is_system' => true],
            ['account_number' => '2600', 'name' => 'Payroll Liabilities',      'account_type' => 'liability', 'account_sub_type' => 'payroll_liabilities'],

            // === EQUITY (3xxx) ===
            ['account_number' => '3000', 'name' => 'Opening Balance Equity', 'account_type' => 'equity', 'account_sub_type' => 'opening_balance_equity', 'is_system' => true],
            ['account_number' => '3100', 'name' => 'Owners Equity',          'account_type' => 'equity', 'account_sub_type' => 'owners_equity'],
            ['account_number' => '3200', 'name' => 'Retained Earnings',     'account_type' => 'equity', 'account_sub_type' => 'retained_earnings',      'is_system' => true],

            // === REVENUE (4xxx) ===
            ['account_number' => '4000', 'name' => 'Sales Revenue',         'account_type' => 'revenue', 'account_sub_type' => 'sales_revenue',          'is_system' => true],
            ['account_number' => '4100', 'name' => 'Service Revenue',       'account_type' => 'revenue', 'account_sub_type' => 'service_revenue',        'is_system' => true],
            ['account_number' => '4200', 'name' => 'Subscription Revenue',  'account_type' => 'revenue', 'account_sub_type' => 'subscription_revenue'],
            ['account_number' => '4900', 'name' => 'Discounts Given',       'account_type' => 'revenue', 'account_sub_type' => 'discounts_given'],

            // === COGS (5xxx) ===
            ['account_number' => '5000', 'name' => 'Cost of Goods Sold', 'account_type' => 'cogs', 'account_sub_type' => 'cogs'],
            ['account_number' => '5100', 'name' => 'Contractor Costs',   'account_type' => 'cogs', 'account_sub_type' => 'contractor_costs'],

            // === OPERATING EXPENSES (6xxx) ===
            ['account_number' => '6000', 'name' => 'Bank Fees',                'account_type' => 'expense', 'account_sub_type' => 'bank_fees',                 'is_system' => true],
            ['account_number' => '6100', 'name' => 'Travel & Meals',           'account_type' => 'expense', 'account_sub_type' => 'travel_and_meals'],
            ['account_number' => '6200', 'name' => 'Office Supplies',          'account_type' => 'expense', 'account_sub_type' => 'office_supplies'],
            ['account_number' => '6300', 'name' => 'Rent',                     'account_type' => 'expense', 'account_sub_type' => 'rent'],
            ['account_number' => '6400', 'name' => 'Utilities',                'account_type' => 'expense', 'account_sub_type' => 'utilities'],
            ['account_number' => '6500', 'name' => 'Cloud Hosting',            'account_type' => 'expense', 'account_sub_type' => 'cloud_hosting'],
            ['account_number' => '6600', 'name' => 'Software & Subscriptions', 'account_type' => 'expense', 'account_sub_type' => 'software_subscriptions'],
            ['account_number' => '6700', 'name' => 'Legal Fees',               'account_type' => 'expense', 'account_sub_type' => 'legal_fees'],
            ['account_number' => '6800', 'name' => 'Professional Services',    'account_type' => 'expense', 'account_sub_type' => 'professional_services'],
            ['account_number' => '6900', 'name' => 'Bad Debt Expense',         'account_type' => 'expense', 'account_sub_type' => 'bad_debt_expense',          'is_system' => true],

            // === OTHER INCOME / EXPENSE (7xxx / 8xxx) ===
            ['account_number' => '7000', 'name' => 'Interest Income',          'account_type' => 'other_income',  'account_sub_type' => 'interest_income'],
            ['account_number' => '7100', 'name' => 'Realized FX Gain',         'account_type' => 'other_income',  'account_sub_type' => 'realized_fx_gain', 'is_system' => true],
            ['account_number' => '8000', 'name' => 'Interest Expense',         'account_type' => 'other_expense', 'account_sub_type' => 'interest_expense'],
            ['account_number' => '8100', 'name' => 'Realized FX Loss',         'account_type' => 'other_expense', 'account_sub_type' => 'realized_fx_loss', 'is_system' => true],
        ];
    }

    /**
     * DR-specific tax accounts. Added on top of the US-default set when country_code='DO'.
     *
     * Sub-types use 'dr_*' prefix so JE composers can find them via WHERE account_sub_type = 'dr_itbis_payable'
     * without ambiguity across multi-country tenants.
     */
    private function dominicanRepublicAccounts(): array
    {
        return [
            ['account_number' => '2210', 'name' => 'ITBIS Payable',         'account_type' => 'liability', 'account_sub_type' => 'dr_itbis_payable',         'currency' => 'DOP', 'is_system' => true],
            ['account_number' => '2220', 'name' => 'ISC Payable',           'account_type' => 'liability', 'account_sub_type' => 'dr_isc_payable',           'currency' => 'DOP', 'is_system' => true],
            ['account_number' => '2230', 'name' => 'ISR Withholding Payable', 'account_type' => 'liability', 'account_sub_type' => 'dr_isr_withholding_payable', 'currency' => 'DOP', 'is_system' => true],
        ];
    }
}
