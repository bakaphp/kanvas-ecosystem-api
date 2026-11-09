<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Social\Messages\Models\Message;

/**
 * The structured SOW stored as the `sow` message body. Input from an agent is normalised here
 * (trimmed, blanks dropped, numbers cast) so the schema check and the consistency check both see
 * the same clean shape, and so the stored message never carries a stray empty bullet.
 */
final class StatementOfWork
{
    public const string WHEN_ON_SIGNATURE = 'on_signature';
    public const string WHEN_DATE = 'date';

    /**
     * @param list<string> $scope
     * @param list<string> $outOfScope
     * @param list<string> $assumptions
     * @param list<array{name: string, acceptance_criteria: string, quote_line_ids: list<int>}> $deliverables
     * @param list<array{label: string, when: string, date: string|null, percent: float|null, amount: float|null}> $paymentSchedule
     * @param list<array{name: string, role: string}> $signatories
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $scope,
        public readonly array $outOfScope,
        public readonly array $assumptions,
        public readonly array $deliverables,
        public readonly ?string $timelineStart,
        public readonly ?string $timelineEnd,
        public readonly array $paymentSchedule,
        public readonly ?string $changeProcess,
        public readonly ?int $paymentTermsId,
        public readonly array $signatories,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $timeline = is_array($input['timeline'] ?? null) ? $input['timeline'] : [];

        return new self(
            summary: (string) Str::trimToNull($input['summary'] ?? null),
            scope: self::strings($input['scope'] ?? []),
            outOfScope: self::strings($input['out_of_scope'] ?? []),
            assumptions: self::strings($input['assumptions'] ?? []),
            deliverables: self::deliverables($input['deliverables'] ?? []),
            timelineStart: Str::trimToNull($timeline['start'] ?? $input['timeline_start'] ?? null),
            timelineEnd: Str::trimToNull($timeline['end'] ?? $input['timeline_end'] ?? null),
            paymentSchedule: self::paymentSchedule($input['payment_schedule'] ?? []),
            changeProcess: Str::trimToNull($input['change_process'] ?? null),
            paymentTermsId: is_numeric($input['payment_terms_id'] ?? null) ? (int) $input['payment_terms_id'] : null,
            signatories: self::signatories($input['signatories'] ?? []),
        );
    }

    public static function fromMessage(Message $message): self
    {
        $payload = is_array($message->message) ? $message->message : json_decode((string) $message->message, true);

        return self::fromInput(is_array($payload) ? $payload : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(int $quoteId): array
    {
        return [
            'quote_id' => $quoteId,
            'summary' => $this->summary,
            'scope' => $this->scope,
            'out_of_scope' => $this->outOfScope,
            'assumptions' => $this->assumptions,
            'deliverables' => $this->deliverables,
            'timeline' => ['start' => $this->timelineStart, 'end' => $this->timelineEnd],
            'payment_schedule' => $this->paymentSchedule,
            'change_process' => $this->changeProcess,
            'payment_terms_id' => $this->paymentTermsId,
            'signatories' => $this->signatories,
        ];
    }

    /**
     * Each payment line as an amount of $total, in schedule order. A percent line is converted; an
     * amount line is taken as written.
     *
     * @return list<float>
     */
    public function paymentAmounts(float $total): array
    {
        return array_map(
            static fn (array $line): float => $line['amount'] ?? round($total * (float) $line['percent'] / 100, 4),
            $this->paymentSchedule,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): ?string => is_scalar($value) ? Str::trimToNull((string) $value) : null, $values),
            static fn (?string $value): bool => $value !== null,
        ));
    }

    /**
     * @return list<array{name: string, acceptance_criteria: string, quote_line_ids: list<int>}>
     */
    private static function deliverables(mixed $values): array
    {
        $deliverables = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_array($value)) {
                continue;
            }

            $deliverables[] = [
                'name' => (string) Str::trimToNull($value['name'] ?? null),
                'acceptance_criteria' => (string) Str::trimToNull($value['acceptance_criteria'] ?? null),
                'quote_line_ids' => array_values(array_map(
                    'intval',
                    array_filter((array) ($value['quote_line_ids'] ?? []), 'is_numeric'),
                )),
            ];
        }

        return $deliverables;
    }

    /**
     * @return list<array{label: string, when: string, date: string|null, percent: float|null, amount: float|null}>
     */
    private static function paymentSchedule(mixed $values): array
    {
        $lines = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_array($value)) {
                continue;
            }

            $lines[] = [
                'label' => (string) Str::trimToNull($value['label'] ?? null),
                'when' => strtolower((string) Str::trimToNull($value['when'] ?? null)),
                'date' => Str::trimToNull($value['date'] ?? null),
                'percent' => is_numeric($value['percent'] ?? null) ? (float) $value['percent'] : null,
                'amount' => is_numeric($value['amount'] ?? null) ? (float) $value['amount'] : null,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{name: string, role: string}>
     */
    private static function signatories(mixed $values): array
    {
        $signatories = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_array($value)) {
                continue;
            }

            $signatories[] = [
                'name' => (string) Str::trimToNull($value['name'] ?? null),
                'role' => (string) Str::trimToNull($value['role'] ?? null),
            ];
        }

        return $signatories;
    }
}
