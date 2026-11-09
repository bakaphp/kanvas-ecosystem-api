<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Services;

use Kanvas\Scribe\PaymentRuns\Enums\CashCheckEnum;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunLineStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRunLine;

/**
 * One shape for every reader of a run: the email template, the agent tool and the chat summary all get the
 * same groups and lines, so what the person who pays reads in the email is what the agent says in chat.
 */
class PaymentRunPresenterService
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(PaymentRun $run): array
    {
        $lines = $run->lines()->orderBy('currency')->orderBy('due_date')->orderBy('id')->get();

        return [
            'payment_run_id' => $run->getId(),
            'status' => $run->status->value,
            'due_by' => $run->due_by?->toDateString(),
            'line_count' => $run->line_count,
            'groups' => array_map(fn (array $group): array => [
                ...$group,
                'lines' => $lines
                    ->filter(fn (PaymentRunLine $line): bool => $line->currency === $group['currency'])
                    ->map(fn (PaymentRunLine $line): array => [
                        'bill_id' => $line->bill_id,
                        'vendor' => $line->vendor_name,
                        'bill_number' => $line->bill_number,
                        'due_date' => $line->due_date?->toDateString(),
                        'amount' => round($line->amount_native, 2),
                        'status' => $line->status->value,
                    ])
                    ->values()
                    ->all(),
            ], (array) $run->groups),
            'skipped' => (array) (($run->metadata ?? [])['skipped'] ?? []),
        ];
    }

    public function summary(PaymentRun $run): string
    {
        $data = $this->toArray($run);
        $out = [sprintf(
            'Payment run #%d (%s): %d bill(s)%s.',
            $data['payment_run_id'],
            $data['status'],
            $data['line_count'],
            $data['due_by'] !== null ? ' due by ' . $data['due_by'] : ''
        )];

        foreach ($data['groups'] as $group) {
            $out[] = sprintf(
                '%s %s from %s — %s',
                $group['currency'],
                number_format((float) $group['total'], 2),
                $group['bank_account_name'] ?? 'no bank account on file for this currency',
                $this->cashLine($group)
            );

            foreach ($group['lines'] as $line) {
                $out[] = sprintf(
                    '  - %s%s, due %s: %s %s%s',
                    $line['vendor'] ?? 'Vendor',
                    $line['bill_number'] !== null ? ' (bill ' . $line['bill_number'] . ')' : '',
                    $line['due_date'] ?? 'n/a',
                    $group['currency'],
                    number_format((float) $line['amount'], 2),
                    $line['status'] !== PaymentRunLineStatusEnum::PENDING->value ? ' [' . $line['status'] . ']' : ''
                );
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $group
     */
    private function cashLine(array $group): string
    {
        $balance = $group['known_balance'] !== null
            ? sprintf('balance %s as of %s (%s)', number_format((float) $group['known_balance'], 2), $group['balance_as_of'] ?? 'unknown date', $group['balance_source'])
            : null;

        return match (CashCheckEnum::tryFrom((string) $group['cash_check'])) {
            CashCheckEnum::ENOUGH => "enough cash: {$balance}",
            CashCheckEnum::SHORT => sprintf('SHORT by %s: %s', number_format((float) $group['shortfall'], 2), $balance),
            default => 'balance unknown (no bank feed or statement yet)',
        };
    }
}
