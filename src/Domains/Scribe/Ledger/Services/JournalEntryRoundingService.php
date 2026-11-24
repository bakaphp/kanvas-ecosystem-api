<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Baka\Support\Money;
use Brick\Math\BigDecimal;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine;

/**
 * A foreign-currency document converts each part to base on its own, so its JE can miss balance by the
 * rounding of those conversions. The composer names the line that takes the difference (revenue or expense,
 * never AR/AP, which must keep matching the document's total). Anything larger than per-line rounding is
 * left alone so the validator still refuses a genuinely unbalanced entry.
 */
class JournalEntryRoundingService
{
    private const string PER_LINE_ROUNDING = '0.00005';

    /**
     * @param list<JournalEntryLine> $lines
     * @return list<JournalEntryLine>
     */
    public static function absorbInto(array $lines, int $index): array
    {
        if (! isset($lines[$index])) {
            return $lines;
        }

        $residual = Money::sum(...array_map(fn (JournalEntryLine $line) => $line->debit_base, $lines))
            ->minus(Money::sum(...array_map(fn (JournalEntryLine $line) => $line->credit_base, $lines)));

        $limit = BigDecimal::of(self::PER_LINE_ROUNDING)->multipliedBy(count($lines));
        if ($residual->isZero() || $residual->abs()->isGreaterThan($limit)) {
            return $lines;
        }

        $line = $lines[$index];
        $lines[$index] = $line->credit_base > 0
            ? JournalEntryLine::from([...$line->toArray(), 'credit_base' => Money::stored(Money::of($line->credit_base)->plus($residual))])
            : JournalEntryLine::from([...$line->toArray(), 'debit_base' => Money::stored(Money::of($line->debit_base)->minus($residual))]);

        return $lines;
    }
}
