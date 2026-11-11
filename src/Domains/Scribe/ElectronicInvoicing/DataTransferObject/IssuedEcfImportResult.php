<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

final readonly class IssuedEcfImportResult
{
    public const string IMPORTED = 'imported';
    public const string UNKNOWN_LAYOUT = 'unknown_layout';
    public const string NOTHING_TO_IMPORT = 'nothing_to_import';

    /**
     * @param list<IssuedEcfRowImportResult> $rows
     * @param list<string> $unreadable
     * @param array{header: list<string>, sample_rows: list<list<string>>}|null $peek
     */
    public function __construct(
        public string $outcome,
        public bool $dryRun,
        public array $rows = [],
        public array $unreadable = [],
        public ?array $peek = null,
    ) {
    }

    /**
     * @return list<IssuedEcfRowImportResult>
     */
    public function with(string $outcome): array
    {
        return array_values(array_filter($this->rows, static fn (IssuedEcfRowImportResult $row): bool => $row->outcome === $outcome));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $present = fn (string $outcome): array => array_map(
            static fn (IssuedEcfRowImportResult $row): array => $row->toArray(),
            $this->with($outcome),
        );
        $openBalance = [];

        foreach ($this->with(IssuedEcfRowImportResult::CREATED) as $row) {
            $openBalance[$row->row->currency] = round(($openBalance[$row->row->currency] ?? 0.0) + $row->row->total, 2);
        }

        return [
            'outcome' => $this->outcome,
            'dry_run' => $this->dryRun,
            'rows_read' => count($this->rows) + count($this->unreadable),
            'created' => $present(IssuedEcfRowImportResult::CREATED),
            'rejected_recorded' => $present(IssuedEcfRowImportResult::REJECTED_RECORDED),
            'skipped_existing' => $present(IssuedEcfRowImportResult::SKIPPED_EXISTING),
            'needs_review' => $present(IssuedEcfRowImportResult::NEEDS_REVIEW),
            'failed' => $present(IssuedEcfRowImportResult::FAILED),
            'unreadable' => $this->unreadable,
            'organizations_created' => count(array_filter($this->rows, static fn (IssuedEcfRowImportResult $r): bool => $r->organizationAction === 'created')),
            'organizations_tax_id_written' => count(array_filter($this->rows, static fn (IssuedEcfRowImportResult $r): bool => $r->organizationAction === 'tax_id_written')),
            'open_balance_imported' => $openBalance,
            ...($this->peek !== null ? ['header' => $this->peek['header'], 'sample_rows' => $this->peek['sample_rows']] : []),
        ];
    }
}
