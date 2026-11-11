<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Services;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Models\QuoteLine;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;

/**
 * The checks that make a SOW safe to sign: signing starts billing (plan decision 8), so a schedule
 * that does not add up to the quote, a quote line nobody promised to deliver, or a payment dated
 * outside the work would all reach the customer as invoices. Each problem is one sentence the agent
 * can act on; an empty list means consistent.
 */
final class SowConsistencyService
{
    private const float TOLERANCE = 0.01;

    /**
     * @return list<string>
     */
    public function problems(StatementOfWork $sow, Quote $quote): array
    {
        return [
            ...$this->paymentScheduleProblems($sow, $quote),
            ...$this->coverageProblems($sow, $quote),
            ...$this->timelineProblems($sow),
            ...$this->paymentTermsProblems($sow, $quote),
        ];
    }

    /**
     * @return list<string>
     */
    private function paymentScheduleProblems(StatementOfWork $sow, Quote $quote): array
    {
        $problems = [];
        $total = (float) $quote->total_native;

        if ($total <= 0) {
            return ["Quote {$quote->getId()} has a total of 0, so there is nothing for a payment schedule to bill."];
        }

        foreach ($sow->paymentSchedule as $index => $line) {
            $position = $index + 1;

            if (($line['percent'] === null) === ($line['amount'] === null)) {
                $problems[] = "Payment line {$position} (\"{$line['label']}\") must give exactly one of percent or amount.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $usesAmounts = array_filter($sow->paymentSchedule, static fn (array $line): bool => $line['amount'] !== null) !== [];
        $usesPercents = array_filter($sow->paymentSchedule, static fn (array $line): bool => $line['percent'] !== null) !== [];

        if ($usesPercents && ! $usesAmounts) {
            $percent = array_sum(array_column($sow->paymentSchedule, 'percent'));

            if (abs($percent - 100) > self::TOLERANCE) {
                $problems[] = sprintf('The payment schedule percentages add up to %s%%, not 100%%.', $this->number($percent));
            }

            return $problems;
        }

        $scheduled = array_sum($sow->paymentAmounts($total));

        if (abs($scheduled - $total) > self::TOLERANCE) {
            $problems[] = sprintf(
                'The payment schedule adds up to %s %s but the quote total is %s %s (difference %s).',
                $quote->currency,
                $this->number($scheduled),
                $quote->currency,
                $this->number($total),
                $this->number($total - $scheduled),
            );
        }

        return $problems;
    }

    /**
     * A quote line is covered when a deliverable lists its id in quote_line_ids, or when its
     * description is named in a deliverable or a scope item. Ids are the precise path; the text match
     * is there so a SOW written in prose still passes when it plainly names the line.
     *
     * @return list<string>
     */
    private function coverageProblems(StatementOfWork $sow, Quote $quote): array
    {
        $coveredIds = array_merge(...array_map(
            static fn (array $deliverable): array => $deliverable['quote_line_ids'],
            $sow->deliverables ?: [['quote_line_ids' => []]],
        ));

        $lineIds = $quote->lines->map(fn (QuoteLine $line): int => (int) $line->id)->all();
        $problems = [];

        foreach (array_diff($coveredIds, $lineIds) as $unknownId) {
            $problems[] = "A deliverable lists quote_line_id {$unknownId}, which is not a line of quote {$quote->getId()}.";
        }

        $texts = array_map(
            static fn (string $text): string => self::normalize($text),
            [
                ...$sow->scope,
                ...array_column($sow->deliverables, 'name'),
                ...array_column($sow->deliverables, 'acceptance_criteria'),
            ],
        );

        foreach ($quote->lines as $line) {
            if (in_array((int) $line->id, $coveredIds, true)) {
                continue;
            }

            $description = self::normalize((string) $line->description);

            if ($description !== '' && $this->mentioned($description, $texts)) {
                continue;
            }

            $problems[] = sprintf(
                'Quote line %d ("%s") is not covered by any deliverable or scope item. Add its id to a '
                . "deliverable's quote_line_ids or name it in scope.",
                (int) $line->id,
                (string) $line->description,
            );
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function timelineProblems(StatementOfWork $sow): array
    {
        $start = $this->date($sow->timelineStart);
        $end = $this->date($sow->timelineEnd);

        if ($start === null || $end === null) {
            return ['The timeline needs a start and an end date as YYYY-MM-DD.'];
        }

        if ($end->lt($start)) {
            return ["The timeline ends ({$sow->timelineEnd}) before it starts ({$sow->timelineStart})."];
        }

        $problems = [];

        foreach ($sow->paymentSchedule as $index => $line) {
            if ($line['when'] !== StatementOfWork::WHEN_DATE) {
                continue;
            }

            $date = $this->date($line['date']);
            $position = $index + 1;

            if ($date === null) {
                $problems[] = "Payment line {$position} (\"{$line['label']}\") is due on a date but gives none.";

                continue;
            }

            if ($date->lt($start) || $date->gt($end)) {
                $problems[] = "Payment line {$position} (\"{$line['label']}\") is dated {$line['date']}, outside "
                    . "the timeline {$sow->timelineStart} to {$sow->timelineEnd}.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function paymentTermsProblems(StatementOfWork $sow, Quote $quote): array
    {
        if ($sow->paymentTermsId === null) {
            return [];
        }

        $exists = PaymentTerm::query()
            ->where('id', $sow->paymentTermsId)
            ->fromApp($quote->apps_id)
            ->fromCompany($quote->companies_id)
            ->notDeleted()
            ->exists();

        return $exists ? [] : ["payment_terms_id {$sow->paymentTermsId} is not a payment term of this company."];
    }

    /**
     * @param list<string> $texts
     */
    private function mentioned(string $description, array $texts): bool
    {
        foreach ($texts as $text) {
            if (str_contains($text, $description)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)));
    }

    private function date(?string $value): ?Carbon
    {
        $date = $value === null ? null : DateHelper::parseYmd($value);

        return $date === null ? null : Carbon::instance($date);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
