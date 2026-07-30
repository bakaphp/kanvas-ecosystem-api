<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

/**
 * Canonical catalog of system + standard account sub-types.
 *
 * Each case carries its own metadata via methods (default name, account number, account type, currency,
 * system flag). The seeder iterates cases; AccountResolverService + JE composers reference the enum directly
 * instead of stringly-typed sub-type names. Typos at compile time, not runtime.
 *
 * Sub-type groups:
 *   - US-default — seeded on every company (the QBO/NetSuite/Xero canonical set)
 *   - DR-extension — seeded only when company country_code='DO'
 *
 * Adding a new account: add a case + extend the three match arms (defaultName, defaultAccountNumber, defaultAccountType)
 * + the optional ones (isSystem, defaultCurrency, isInUsDefault, isInDominicanRepublicSeed).
 *
 * @see plan §3.5 — canonical accounting model
 * @see plan §7.7 — "System accounts are undeletable"
 */
enum AccountSubTypeEnum: string
{
    // ── Assets (1xxx) ────────────────────────────────────────────────────
    case CASH_CHECKING = 'cash_checking';
    case CASH_SAVINGS = 'cash_savings';
    case CASH_MONEY_MARKET = 'cash_money_market';
    case CASH_ON_HAND = 'cash_on_hand';
    case ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    case VENDOR_PREPAYMENTS = 'vendor_prepayments';
    case INVENTORY_ASSET = 'inventory_asset';
    case PREPAID_EXPENSES = 'prepaid_expenses';
    case UNDEPOSITED_FUNDS = 'undeposited_funds';
    case INPUT_TAX_RECEIVABLE = 'input_tax_receivable';
    // Clearing account for bank movements we can't yet explain. Self-draining: a non-zero balance means
    // cash moved that nobody has classified, and the reclass fires the moment the real document appears.
    case SUSPENSE = 'suspense';
    // DR-extension (input tax on Bills)
    case DR_ITBIS_RECEIVABLE = 'dr_itbis_receivable';

    // ── Liabilities (2xxx) ───────────────────────────────────────────────
    case ACCOUNTS_PAYABLE = 'accounts_payable';
    case CREDIT_CARD_LIABILITY = 'credit_card_liability';
    case SALES_TAX_PAYABLE = 'sales_tax_payable';
    case CUSTOMER_PREPAYMENTS = 'customer_prepayments';
    case CUSTOMER_OVERPAYMENTS = 'customer_overpayments';
    case DUE_TO_EMPLOYEES = 'due_to_employees';
    case PAYROLL_LIABILITIES = 'payroll_liabilities';
    // DR-extension
    case DR_ITBIS_PAYABLE = 'dr_itbis_payable';
    case DR_ISC_PAYABLE = 'dr_isc_payable';
    case DR_ISR_WITHHOLDING_PAYABLE = 'dr_isr_withholding_payable';

    // ── Equity (3xxx) ────────────────────────────────────────────────────
    case OPENING_BALANCE_EQUITY = 'opening_balance_equity';
    case OWNERS_EQUITY = 'owners_equity';
    case RETAINED_EARNINGS = 'retained_earnings';

    // ── Revenue (4xxx) ───────────────────────────────────────────────────
    case SALES_REVENUE = 'sales_revenue';
    case SERVICE_REVENUE = 'service_revenue';
    case SUBSCRIPTION_REVENUE = 'subscription_revenue';
    case DISCOUNTS_GIVEN = 'discounts_given';

    // ── COGS (5xxx) ──────────────────────────────────────────────────────
    case COGS = 'cogs';
    case CONTRACTOR_COSTS = 'contractor_costs';

    // ── Operating Expenses (6xxx) ────────────────────────────────────────
    case BANK_FEES = 'bank_fees';
    case TRAVEL_AND_MEALS = 'travel_and_meals';
    case OFFICE_SUPPLIES = 'office_supplies';
    case RENT = 'rent';
    case UTILITIES = 'utilities';
    case CLOUD_HOSTING = 'cloud_hosting';
    case SOFTWARE_SUBSCRIPTIONS = 'software_subscriptions';
    case LEGAL_FEES = 'legal_fees';
    case PROFESSIONAL_SERVICES = 'professional_services';
    case BAD_DEBT_EXPENSE = 'bad_debt_expense';

    // ── Other Income / Expense (7xxx / 8xxx) ────────────────────────────
    case INTEREST_INCOME = 'interest_income';
    case REALIZED_FX_GAIN = 'realized_fx_gain';
    case INTEREST_EXPENSE = 'interest_expense';
    case REALIZED_FX_LOSS = 'realized_fx_loss';

    public function defaultAccountNumber(): string
    {
        return match ($this) {
            self::CASH_CHECKING => '1000',
            self::CASH_SAVINGS => '1010',
            self::CASH_MONEY_MARKET => '1020',
            self::CASH_ON_HAND => '1030',
            self::ACCOUNTS_RECEIVABLE => '1100',
            self::VENDOR_PREPAYMENTS => '1200',
            self::INVENTORY_ASSET => '1300',
            self::PREPAID_EXPENSES => '1400',
            self::UNDEPOSITED_FUNDS => '1500',
            self::INPUT_TAX_RECEIVABLE => '1600',
            self::DR_ITBIS_RECEIVABLE => '1610',
            self::SUSPENSE => '1900',
            self::ACCOUNTS_PAYABLE => '2000',
            self::CREDIT_CARD_LIABILITY => '2100',
            self::SALES_TAX_PAYABLE => '2200',
            self::DR_ITBIS_PAYABLE => '2210',
            self::DR_ISC_PAYABLE => '2220',
            self::DR_ISR_WITHHOLDING_PAYABLE => '2230',
            self::CUSTOMER_PREPAYMENTS => '2300',
            self::CUSTOMER_OVERPAYMENTS => '2400',
            self::DUE_TO_EMPLOYEES => '2500',
            self::PAYROLL_LIABILITIES => '2600',
            self::OPENING_BALANCE_EQUITY => '3000',
            self::OWNERS_EQUITY => '3100',
            self::RETAINED_EARNINGS => '3200',
            self::SALES_REVENUE => '4000',
            self::SERVICE_REVENUE => '4100',
            self::SUBSCRIPTION_REVENUE => '4200',
            self::DISCOUNTS_GIVEN => '4900',
            self::COGS => '5000',
            self::CONTRACTOR_COSTS => '5100',
            self::BANK_FEES => '6000',
            self::TRAVEL_AND_MEALS => '6100',
            self::OFFICE_SUPPLIES => '6200',
            self::RENT => '6300',
            self::UTILITIES => '6400',
            self::CLOUD_HOSTING => '6500',
            self::SOFTWARE_SUBSCRIPTIONS => '6600',
            self::LEGAL_FEES => '6700',
            self::PROFESSIONAL_SERVICES => '6800',
            self::BAD_DEBT_EXPENSE => '6900',
            self::INTEREST_INCOME => '7000',
            self::REALIZED_FX_GAIN => '7100',
            self::INTEREST_EXPENSE => '8000',
            self::REALIZED_FX_LOSS => '8100',
        };
    }

    public function defaultName(): string
    {
        return match ($this) {
            self::CASH_CHECKING => 'Cash — Checking',
            self::CASH_SAVINGS => 'Cash — Savings',
            self::CASH_MONEY_MARKET => 'Cash — Money Market',
            self::CASH_ON_HAND => 'Cash on Hand',
            self::ACCOUNTS_RECEIVABLE => 'Accounts Receivable',
            self::VENDOR_PREPAYMENTS => 'Vendor Prepayments',
            self::INVENTORY_ASSET => 'Inventory Asset',
            self::PREPAID_EXPENSES => 'Prepaid Expenses',
            self::UNDEPOSITED_FUNDS => 'Undeposited Funds',
            self::INPUT_TAX_RECEIVABLE => 'Input Tax Receivable',
            self::DR_ITBIS_RECEIVABLE => 'ITBIS Receivable (Input)',
            self::SUSPENSE => 'Suspense — Unclassified',
            self::ACCOUNTS_PAYABLE => 'Accounts Payable',
            self::CREDIT_CARD_LIABILITY => 'Credit Card Liability',
            self::SALES_TAX_PAYABLE => 'Sales Tax Payable',
            self::DR_ITBIS_PAYABLE => 'ITBIS Payable',
            self::DR_ISC_PAYABLE => 'ISC Payable',
            self::DR_ISR_WITHHOLDING_PAYABLE => 'ISR Withholding Payable',
            self::CUSTOMER_PREPAYMENTS => 'Customer Prepayments',
            self::CUSTOMER_OVERPAYMENTS => 'Customer Overpayments',
            self::DUE_TO_EMPLOYEES => 'Due to Employees',
            self::PAYROLL_LIABILITIES => 'Payroll Liabilities',
            self::OPENING_BALANCE_EQUITY => 'Opening Balance Equity',
            self::OWNERS_EQUITY => 'Owners Equity',
            self::RETAINED_EARNINGS => 'Retained Earnings',
            self::SALES_REVENUE => 'Sales Revenue',
            self::SERVICE_REVENUE => 'Service Revenue',
            self::SUBSCRIPTION_REVENUE => 'Subscription Revenue',
            self::DISCOUNTS_GIVEN => 'Discounts Given',
            self::COGS => 'Cost of Goods Sold',
            self::CONTRACTOR_COSTS => 'Contractor Costs',
            self::BANK_FEES => 'Bank Fees',
            self::TRAVEL_AND_MEALS => 'Travel & Meals',
            self::OFFICE_SUPPLIES => 'Office Supplies',
            self::RENT => 'Rent',
            self::UTILITIES => 'Utilities',
            self::CLOUD_HOSTING => 'Cloud Hosting',
            self::SOFTWARE_SUBSCRIPTIONS => 'Software & Subscriptions',
            self::LEGAL_FEES => 'Legal Fees',
            self::PROFESSIONAL_SERVICES => 'Professional Services',
            self::BAD_DEBT_EXPENSE => 'Bad Debt Expense',
            self::INTEREST_INCOME => 'Interest Income',
            self::REALIZED_FX_GAIN => 'Realized FX Gain',
            self::INTEREST_EXPENSE => 'Interest Expense',
            self::REALIZED_FX_LOSS => 'Realized FX Loss',
        };
    }

    public function defaultAccountType(): AccountTypeEnum
    {
        return match ($this) {
            self::CASH_CHECKING,
            self::CASH_SAVINGS,
            self::CASH_MONEY_MARKET,
            self::CASH_ON_HAND,
            self::ACCOUNTS_RECEIVABLE,
            self::VENDOR_PREPAYMENTS,
            self::INVENTORY_ASSET,
            self::PREPAID_EXPENSES,
            self::UNDEPOSITED_FUNDS,
            self::INPUT_TAX_RECEIVABLE,
            self::SUSPENSE,
            self::DR_ITBIS_RECEIVABLE => AccountTypeEnum::ASSET,

            self::ACCOUNTS_PAYABLE,
            self::CREDIT_CARD_LIABILITY,
            self::SALES_TAX_PAYABLE,
            self::DR_ITBIS_PAYABLE,
            self::DR_ISC_PAYABLE,
            self::DR_ISR_WITHHOLDING_PAYABLE,
            self::CUSTOMER_PREPAYMENTS,
            self::CUSTOMER_OVERPAYMENTS,
            self::DUE_TO_EMPLOYEES,
            self::PAYROLL_LIABILITIES => AccountTypeEnum::LIABILITY,

            self::OPENING_BALANCE_EQUITY,
            self::OWNERS_EQUITY,
            self::RETAINED_EARNINGS => AccountTypeEnum::EQUITY,

            self::SALES_REVENUE,
            self::SERVICE_REVENUE,
            self::SUBSCRIPTION_REVENUE,
            self::DISCOUNTS_GIVEN => AccountTypeEnum::REVENUE,

            self::COGS,
            self::CONTRACTOR_COSTS => AccountTypeEnum::COGS,

            self::BANK_FEES,
            self::TRAVEL_AND_MEALS,
            self::OFFICE_SUPPLIES,
            self::RENT,
            self::UTILITIES,
            self::CLOUD_HOSTING,
            self::SOFTWARE_SUBSCRIPTIONS,
            self::LEGAL_FEES,
            self::PROFESSIONAL_SERVICES,
            self::BAD_DEBT_EXPENSE => AccountTypeEnum::EXPENSE,

            self::INTEREST_INCOME,
            self::REALIZED_FX_GAIN => AccountTypeEnum::OTHER_INCOME,

            self::INTEREST_EXPENSE,
            self::REALIZED_FX_LOSS => AccountTypeEnum::OTHER_EXPENSE,
        };
    }

    /**
     * System accounts (per plan §7.7) cannot be deleted by tenants. JE composers look them up by sub_type
     * to compose JEs — losing them would break the system.
     */
    public function isSystem(): bool
    {
        return match ($this) {
            self::CASH_CHECKING,
            self::ACCOUNTS_RECEIVABLE,
            self::VENDOR_PREPAYMENTS,
            self::ACCOUNTS_PAYABLE,
            self::CREDIT_CARD_LIABILITY,
            self::SALES_TAX_PAYABLE,
            self::DR_ITBIS_PAYABLE,
            self::DR_ISC_PAYABLE,
            self::DR_ISR_WITHHOLDING_PAYABLE,
            self::INPUT_TAX_RECEIVABLE,
            self::DR_ITBIS_RECEIVABLE,
            self::SUSPENSE,
            self::CUSTOMER_PREPAYMENTS,
            self::CUSTOMER_OVERPAYMENTS,
            self::DUE_TO_EMPLOYEES,
            self::OPENING_BALANCE_EQUITY,
            self::RETAINED_EARNINGS,
            self::SALES_REVENUE,
            self::SERVICE_REVENUE,
            self::BANK_FEES,
            self::BAD_DEBT_EXPENSE,
            self::REALIZED_FX_GAIN,
            self::REALIZED_FX_LOSS => true,
            default => false,
        };
    }

    /**
     * DR-specific accounts use the company's DOP default; everything else inherits the company default currency.
     */
    public function defaultCurrency(): ?string
    {
        return match ($this) {
            self::DR_ITBIS_PAYABLE,
            self::DR_ITBIS_RECEIVABLE,
            self::DR_ISC_PAYABLE,
            self::DR_ISR_WITHHOLDING_PAYABLE => 'DOP',
            default => null,
        };
    }

    public function isDominicanRepublicSpecific(): bool
    {
        return match ($this) {
            self::DR_ITBIS_PAYABLE,
            self::DR_ITBIS_RECEIVABLE,
            self::DR_ISC_PAYABLE,
            self::DR_ISR_WITHHOLDING_PAYABLE => true,
            default => false,
        };
    }

    /**
     * Returns the cases seeded on every company (the QBO/NetSuite/Xero canonical set).
     *
     * @return list<self>
     */
    public static function usDefaultSet(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => ! $case->isDominicanRepublicSpecific()
        ));
    }

    /**
     * Returns US-default set plus the DR-extension cases. Used when company country_code='DO'.
     *
     * @return list<self>
     */
    public static function dominicanRepublicSet(): array
    {
        return self::cases();
    }
}
