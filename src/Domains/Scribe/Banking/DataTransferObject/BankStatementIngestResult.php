<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Models\BankStatement;

final class BankStatementIngestResult
{
    /**
     * @param array<string, int> $matchOutcomes matcher outcome => count, for the rows this statement landed
     * @param list<string>|null $header the CSV header row, only on UNKNOWN_LAYOUT
     * @param list<list<string>>|null $sampleRows a few data rows under that header
     */
    public function __construct(
        public readonly BankStatementIngestOutcomeEnum $outcome,
        public readonly ?BankStatement $statement = null,
        public readonly int $newRows = 0,
        public readonly int $duplicateRows = 0,
        public readonly array $matchOutcomes = [],
        public readonly ?BankStatementRejectionEnum $reasonCode = null,
        public readonly ?string $reason = null,
        public readonly ?array $header = null,
        public readonly ?array $sampleRows = null,
    ) {
    }

    public static function rejected(BankStatement $statement): self
    {
        return new self(
            outcome: BankStatementIngestOutcomeEnum::REJECTED,
            statement: $statement,
            reasonCode: $statement->rejected_reason_code,
            reason: $statement->rejected_reason,
        );
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $sampleRows
     */
    public static function unknownLayout(array $header, array $sampleRows): self
    {
        return new self(
            outcome: BankStatementIngestOutcomeEnum::UNKNOWN_LAYOUT,
            reason: 'No statement mapping matches this file\'s columns.',
            header: $header,
            sampleRows: $sampleRows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'outcome' => $this->outcome->value,
            'bank_statement_id' => $this->statement?->getId(),
            'bank_account_id' => $this->statement?->bank_account_id,
            'period_start' => $this->statement?->period_start?->toDateString(),
            'period_end' => $this->statement?->period_end?->toDateString(),
            'opening_balance' => $this->statement?->opening_balance,
            'closing_balance' => $this->statement?->closing_balance,
            'currency' => $this->statement?->currency,
            'row_count' => $this->statement?->row_count,
            'new_rows' => $this->outcome === BankStatementIngestOutcomeEnum::POSTED ? $this->newRows : null,
            'already_on_file_rows' => $this->outcome === BankStatementIngestOutcomeEnum::POSTED ? $this->duplicateRows : null,
            'match_outcomes' => $this->matchOutcomes ?: null,
            'reason_code' => $this->reasonCode?->value,
            'reason' => $this->reason,
            'guidance' => $this->reasonCode?->guidance(),
            'header' => $this->header,
            'sample_rows' => $this->sampleRows,
        ], fn (mixed $value): bool => $value !== null);
    }
}
