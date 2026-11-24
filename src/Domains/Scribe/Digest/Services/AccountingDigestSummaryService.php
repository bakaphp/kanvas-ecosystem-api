<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Digest\Services;

/**
 * The digest as a few plain lines the agent can post in chat (and that the ledger keeps), built from the same
 * array the email template renders so the two never disagree.
 */
class AccountingDigestSummaryService
{
    /**
     * @param array<string, mixed> $digest
     */
    public function text(array $digest): string
    {
        $ar = $digest['ar'];
        $ap = $digest['ap'];
        $banking = $digest['banking'];
        $approvals = $digest['approvals'];

        $lines = ["Accounting digest for {$digest['company_name']} — {$digest['date']}"];

        $lines[] = sprintf(
            'AR: %d invoice(s) issued, e-CF %d accepted / %d rejected / %d result overdue, %d credit note(s), %d sent, %s, %d paid.',
            $ar['invoices_issued'],
            $ar['ecf_accepted'],
            $ar['ecf_rejected'],
            $ar['ecf_result_overdue'],
            $ar['credit_notes_issued'],
            $ar['invoices_sent'],
            $ar['engagements'] === null
                ? 'opens not tracked yet'
                : sprintf('%d opened', (int) ($ar['engagements']['opened'] ?? 0)),
            $ar['invoices_paid'],
        );

        if ($ar['collections_due'] !== [] || $ar['overdue'] !== []) {
            $lines[] = sprintf(
                'Collections: due in %d days %s; overdue %s.',
                AccountingDigestBuilderService::DUE_SOON_DAYS,
                $this->amounts($ar['collections_due']),
                $this->amounts($ar['overdue']),
            );
        }

        if ($ar['created_by_source'] !== []) {
            $lines[] = 'Invoices created by source: ' . $this->pairs($ar['created_by_source']) . '.';
        }

        $lines[] = sprintf(
            'AP: %d bill(s) received (%d as e-CF), %d approved, %d paid; %d payment(s) recorded, %d confirmed by the bank, %d not seen in the bank.',
            $ap['bills_received'],
            $ap['received_ecf'],
            $ap['bills_approved'],
            $ap['bills_paid'],
            $ap['payments_recorded'],
            $ap['payments_confirmed'],
            $ap['payments_unconfirmed'],
        );

        if ($ap['bills_due_soon'] !== []) {
            $lines[] = sprintf(
                'Approved bills due within %d days: %s.',
                AccountingDigestBuilderService::DUE_SOON_DAYS,
                $this->amounts($ap['bills_due_soon']),
            );
        }

        foreach ($ap['open_payment_runs'] as $run) {
            $lines[] = sprintf('Payment run #%d is %s (%d bill(s)).', $run['id'], $run['status'], $run['line_count']);
        }

        $lines[] = sprintf(
            'Bank statements: %d ingested, %d rejected, %d requested.',
            $banking['statements_ingested'],
            $banking['statements_rejected'],
            $banking['statements_requested'],
        );

        foreach ($banking['accounts'] as $account) {
            $lines[] = sprintf(
                '  %s %s: covered to %s%s.',
                $account['account_name'],
                $account['currency'],
                $account['covered_through'] ?? 'never',
                $account['is_behind'] ? ' (behind)' : '',
            );
        }

        foreach ($digest['sequences'] as $sequence) {
            $lines[] = sprintf(
                'Sequence %s needs a new range: %s left%s (%s).',
                $sequence['prefix'],
                $sequence['remaining'] ?? 'unknown',
                $sequence['expires_at'] !== null ? ', expires ' . $sequence['expires_at'] : '',
                implode(', ', $sequence['reasons']),
            );
        }

        $lines[] = $approvals['waiting'] > 0
            ? sprintf('Approvals: %d waiting, oldest %dh.', $approvals['waiting'], $approvals['oldest_waiting_hours'])
            : 'Approvals: none waiting.';

        foreach ($approvals['approvers'] as $approver) {
            $lines[] = sprintf(
                '  %s: %d decided in %d days, avg %sh, %d%% rejected.',
                $approver['name'],
                $approver['decided'],
                AccountingDigestBuilderService::APPROVER_STATS_DAYS,
                $approver['avg_hours'],
                (int) round($approver['rejection_rate'] * 100),
            );
        }

        if ($digest['issue_ecf'] !== null) {
            $lines[] = sprintf(
                'issue_ecf: agent invoice approval is OFF (%s) since %s, by %s. e-CFs the agent issues reach DGII without a person.',
                $digest['issue_ecf']['how'],
                $digest['issue_ecf']['since'] ?? 'an unknown date',
                $digest['issue_ecf']['by'] ?? 'someone unrecorded',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{currency: string, count: int, amount: float}> $rows
     */
    private function amounts(array $rows): string
    {
        if ($rows === []) {
            return 'none';
        }

        return implode(', ', array_map(
            fn (array $row): string => sprintf('%s %s (%d)', $row['currency'], number_format($row['amount'], 2), $row['count']),
            $rows
        ));
    }

    /**
     * @param array<string, int> $pairs
     */
    private function pairs(array $pairs): string
    {
        return implode(', ', array_map(
            fn (string $key, int $value): string => "{$key} {$value}",
            array_keys($pairs),
            $pairs
        ));
    }
}
