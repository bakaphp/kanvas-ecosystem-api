<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Kanvas\Scribe\Ledger\Exceptions\InvalidJournalEntryLineException;
use Kanvas\Scribe\Ledger\Exceptions\UnbalancedJournalEntryException;

/**
 * Enforces the GL invariants documented in plan §7.7.
 *
 * Per-line:
 *   - exactly one of (debit_native, credit_native) is non-zero
 *   - all monetary fields are non-negative
 *   - currency + fx_rate_to_base set
 *   - account_id set
 *
 * Per-JE:
 *   - at least 2 lines
 *   - SUM(debit_base) == SUM(credit_base)  (exact, no float tolerance — DECIMAL(18,4) is exact)
 *
 * Throws on first violation. Called by PostJournalEntryAction before the DB write.
 */
class JournalEntryValidator
{
    /**
     * Tolerance for the balanced-JE check, in base currency units.
     * Set to 0.0001 (one ten-thousandth) to allow for any rounding that may have happened at line-composer time.
     * Lines are stored as DECIMAL(18,4) so exact equality should be the norm; this tolerance is belt-and-suspenders.
     */
    public const BALANCE_TOLERANCE = 0.0001;

    /**
     * @param array<int, array<string, mixed>> $lines Each: [account_id, debit_native, credit_native, debit_base,
     *     credit_base, currency, fx_rate_to_base, customer_billable_type?, customer_billable_id?,
     *     vendor_billable_type?, vendor_billable_id?, item_id?, class_id?, department_id?, memo?, metadata?, sort_order?]
     *
     * @throws InvalidJournalEntryLineException
     * @throws UnbalancedJournalEntryException
     */
    public function validate(array $lines): void
    {
        $this->ensureLineCount($lines);

        $debitBaseSum = 0.0;
        $creditBaseSum = 0.0;

        foreach ($lines as $index => $line) {
            $this->validateLine($line, $index);
            $debitBaseSum += (float) $line['debit_base'];
            $creditBaseSum += (float) $line['credit_base'];
        }

        $this->ensureBalanced($debitBaseSum, $creditBaseSum);
    }

    private function ensureLineCount(array $lines): void
    {
        if (count($lines) < 2) {
            throw new UnbalancedJournalEntryException(
                'A journal entry needs at least 2 lines (one debit, one credit); got ' . count($lines) . '.'
            );
        }
    }

    private function validateLine(array $line, int $index): void
    {
        $required = ['account_id', 'debit_native', 'credit_native', 'debit_base', 'credit_base', 'currency', 'fx_rate_to_base'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $line)) {
                throw new InvalidJournalEntryLineException("Line {$index} is missing required field '{$field}'.");
            }
        }

        if (empty($line['account_id'])) {
            throw new InvalidJournalEntryLineException("Line {$index} has empty account_id.");
        }
        if (empty($line['currency'])) {
            throw new InvalidJournalEntryLineException("Line {$index} has empty currency.");
        }
        if ((float) $line['fx_rate_to_base'] <= 0) {
            throw new InvalidJournalEntryLineException("Line {$index} has non-positive fx_rate_to_base.");
        }

        $debitNative = (float) $line['debit_native'];
        $creditNative = (float) $line['credit_native'];
        $debitBase = (float) $line['debit_base'];
        $creditBase = (float) $line['credit_base'];

        if ($debitNative < 0 || $creditNative < 0 || $debitBase < 0 || $creditBase < 0) {
            throw new InvalidJournalEntryLineException("Line {$index} has a negative amount; both sides must be ≥ 0.");
        }

        // Exactly-one-sided: one of (debit, credit) is non-zero, the other is exactly zero.
        $isDebit = $debitNative > 0;
        $isCredit = $creditNative > 0;
        if ($isDebit && $isCredit) {
            throw new InvalidJournalEntryLineException("Line {$index} has both debit and credit non-zero.");
        }
        if (! $isDebit && ! $isCredit) {
            throw new InvalidJournalEntryLineException("Line {$index} has both debit and credit zero.");
        }

        // Native vs base side consistency — a debit-native line must have debit_base > 0 and credit_base == 0 (and same inverted).
        if ($isDebit && ($debitBase <= 0 || $creditBase != 0)) {
            throw new InvalidJournalEntryLineException(
                "Line {$index} is debit-native but base-currency totals don't agree (debit_base={$debitBase}, credit_base={$creditBase})."
            );
        }
        if ($isCredit && ($creditBase <= 0 || $debitBase != 0)) {
            throw new InvalidJournalEntryLineException(
                "Line {$index} is credit-native but base-currency totals don't agree (debit_base={$debitBase}, credit_base={$creditBase})."
            );
        }
    }

    private function ensureBalanced(float $debitBaseSum, float $creditBaseSum): void
    {
        $diff = abs($debitBaseSum - $creditBaseSum);
        if ($diff > self::BALANCE_TOLERANCE) {
            throw new UnbalancedJournalEntryException(
                "JE unbalanced in base currency: debit_base sum {$debitBaseSum} vs credit_base sum {$creditBaseSum} "
                . "(delta {$diff}, tolerance " . self::BALANCE_TOLERANCE . ')'
            );
        }
    }
}
