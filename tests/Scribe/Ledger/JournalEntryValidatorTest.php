<?php

declare(strict_types=1);

namespace Tests\Scribe\Ledger;

use Kanvas\Scribe\Ledger\Exceptions\InvalidJournalEntryLineException;
use Kanvas\Scribe\Ledger\Exceptions\UnbalancedJournalEntryException;
use Kanvas\Scribe\Ledger\Services\JournalEntryValidatorService;
use Tests\TestCase;

/**
 * Pure unit test — no DB. Validates the in-memory JE shape invariants.
 *
 * @see plan §7.7 GL invariants
 */
class JournalEntryValidatorTest extends TestCase
{
    private JournalEntryValidatorService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new JournalEntryValidatorService();
    }

    /**
     * Balanced 2-line JE: DR Cash $1000 / CR Revenue $1000.
     */
    public function test_balanced_two_line_je_passes(): void
    {
        $lines = [
            $this->makeLine(account_id: 1, debit: 1000.00),
            $this->makeLine(account_id: 4000, credit: 1000.00),
        ];

        $this->validator->validate($lines);

        $this->assertTrue(true, 'Balanced JE should pass validation without throwing.');
    }

    /**
     * Three-line JE typical of an invoice: DR AR / CR Revenue / CR Tax Payable.
     */
    public function test_balanced_three_line_invoice_je_passes(): void
    {
        $lines = [
            $this->makeLine(account_id: 1100, debit: 1180.00),                              // AR
            $this->makeLine(account_id: 4100, credit: 1000.00),                             // Service Revenue
            $this->makeLine(account_id: 2200, credit: 180.00),                              // Sales Tax Payable
        ];

        $this->validator->validate($lines);

        $this->assertTrue(true);
    }

    public function test_unbalanced_je_throws_unbalanced_exception(): void
    {
        $lines = [
            $this->makeLine(account_id: 1, debit: 1000.00),
            $this->makeLine(account_id: 2, credit: 999.50),
        ];

        $this->expectException(UnbalancedJournalEntryException::class);
        $this->validator->validate($lines);
    }

    public function test_single_line_je_throws_unbalanced_exception(): void
    {
        $this->expectException(UnbalancedJournalEntryException::class);
        $this->validator->validate([$this->makeLine(account_id: 1, debit: 100)]);
    }

    public function test_both_sides_set_throws_invalid_line(): void
    {
        $lines = [
            ['account_id' => 1, 'debit_native' => 100, 'credit_native' => 50, 'debit_base' => 100, 'credit_base' => 0, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
            ['account_id' => 2, 'debit_native' => 0,   'credit_native' => 100, 'debit_base' => 0,   'credit_base' => 100, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
        ];

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->validator->validate($lines);
    }

    public function test_both_sides_zero_throws_invalid_line(): void
    {
        $lines = [
            ['account_id' => 1, 'debit_native' => 0, 'credit_native' => 0, 'debit_base' => 0, 'credit_base' => 0, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
            ['account_id' => 2, 'debit_native' => 0, 'credit_native' => 100, 'debit_base' => 0, 'credit_base' => 100, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
        ];

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->validator->validate($lines);
    }

    public function test_negative_amount_throws_invalid_line(): void
    {
        $lines = [
            ['account_id' => 1, 'debit_native' => -100, 'credit_native' => 0, 'debit_base' => -100, 'credit_base' => 0, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
            ['account_id' => 2, 'debit_native' => 0, 'credit_native' => 100, 'debit_base' => 0, 'credit_base' => 100, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
        ];

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->validator->validate($lines);
    }

    public function test_missing_account_id_throws_invalid_line(): void
    {
        $lines = [
            ['debit_native' => 100, 'credit_native' => 0, 'debit_base' => 100, 'credit_base' => 0, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
            ['account_id' => 2, 'debit_native' => 0, 'credit_native' => 100, 'debit_base' => 0, 'credit_base' => 100, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
        ];

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->validator->validate($lines);
    }

    public function test_fx_rate_zero_throws_invalid_line(): void
    {
        $lines = [
            ['account_id' => 1, 'debit_native' => 100, 'credit_native' => 0, 'debit_base' => 100, 'credit_base' => 0, 'currency' => 'USD', 'fx_rate_to_base' => 0],
            ['account_id' => 2, 'debit_native' => 0, 'credit_native' => 100, 'debit_base' => 0, 'credit_base' => 100, 'currency' => 'USD', 'fx_rate_to_base' => 1.0],
        ];

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->validator->validate($lines);
    }

    /**
     * Multi-currency JE (DOP native, USD base) must balance in base currency.
     * 1180 DOP @ 0.0167 = $19.706 base — matches across DR/CR sides.
     */
    public function test_multi_currency_je_balances_in_base(): void
    {
        $lines = [
            ['account_id' => 1100, 'debit_native' => 1180.00, 'credit_native' => 0, 'debit_base' => 19.706, 'credit_base' => 0, 'currency' => 'DOP', 'fx_rate_to_base' => 0.0167],
            ['account_id' => 4100, 'debit_native' => 0, 'credit_native' => 1000.00, 'debit_base' => 0, 'credit_base' => 16.700, 'currency' => 'DOP', 'fx_rate_to_base' => 0.0167],
            ['account_id' => 2200, 'debit_native' => 0, 'credit_native' => 180.00,  'debit_base' => 0, 'credit_base' => 3.006,  'currency' => 'DOP', 'fx_rate_to_base' => 0.0167],
        ];

        $this->validator->validate($lines);
        $this->assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeLine(int $account_id, float $debit = 0, float $credit = 0): array
    {
        return [
            'account_id' => $account_id,
            'debit_native' => $debit,
            'credit_native' => $credit,
            'debit_base' => $debit,
            'credit_base' => $credit,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
        ];
    }
}
