<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Baka\Support\Str;
use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Services\MappedCsvService;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfExportRow;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;

/**
 * Turns a provider's issued-documents CSV export into rows through the import template, so every column name
 * and status spelling lives in resources/import-templates (src/Kanvas/Imports/CLAUDE.md); this class knows
 * only the target keys. A row the file cannot make sense of is reported, never guessed.
 */
class IssuedEcfExportParserService
{
    private const string ENCF_PATTERN = '/^E\d{10,12}$/';

    public function __construct(
        private readonly MappedCsvService $csv = new MappedCsvService(),
        private readonly ImportTemplateEnum $template = ImportTemplateEnum::WEBPOS_ISSUED_ECF_CSV,
    ) {
    }

    public function matches(string $contents): bool
    {
        return $this->csv->matches($contents, $this->mapping());
    }

    /**
     * @return array{header: list<string>, sample_rows: list<list<string>>}
     */
    public function peek(string $contents): array
    {
        return $this->csv->peek($contents);
    }

    /**
     * @return array{rows: list<IssuedEcfExportRow>, unreadable: list<string>}
     */
    public function parse(string $contents): array
    {
        $mapping = $this->mapping();
        $rows = [];
        $unreadable = [];

        foreach ($this->csv->rowsUnderHeader($contents, $mapping) ?? [] as $index => $record) {
            $number = $index + 1;
            $row = $this->toRow($record, $mapping, $number);

            if (is_string($row)) {
                $unreadable[] = "Row {$number}: {$row}";

                continue;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'unreadable' => $unreadable];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(): array
    {
        return $this->template->template()->mapping;
    }

    /**
     * The row, or why it cannot be one.
     *
     * @param array<string, string> $record
     * @param array<string, mixed> $mapping
     */
    private function toRow(array $record, array $mapping, int $number): IssuedEcfExportRow|string
    {
        $text = fn (string $key): ?string => FilesystemRowMapper::resolveText($mapping[$key] ?? null, $record);
        $decimal = $text('decimal_separator') ?? '.';
        $amount = fn (string $key): ?float => MappedCsvService::number($text($key), $decimal);

        $encf = strtoupper((string) $text('encf'));
        if (preg_match(self::ENCF_PATTERN, $encf) !== 1) {
            return $encf === '' ? 'no e-NCF' : "\"{$encf}\" is not an e-NCF";
        }

        if (filter_var($text('cancelled') ?? '', FILTER_VALIDATE_BOOLEAN)) {
            return "{$encf} is marked cancelled in the provider; not imported";
        }

        $issuedText = $text('issued_at');
        $issuedAt = $issuedText !== null ? MappedCsvService::date($issuedText, $text('date_format')) : null;
        if ($issuedAt === null) {
            return "no readable issue date ('{$issuedText}')";
        }

        $total = $amount('total');
        if ($total === null) {
            return 'no readable total';
        }

        $tax = $amount('tax') ?? 0.0;
        $subtotal = $amount('subtotal') ?? round($total - $tax, 4);
        if (abs($subtotal + $tax - $total) > 0.011) {
            return sprintf('subtotal %.2f + tax %.2f is not the total %.2f', $subtotal, $tax, $total);
        }

        $status = FiscalDocumentStatusEnum::tryFrom((string) $text('status'));
        if ($status === null) {
            return "unknown status \"{$text('authority_status')}\"";
        }

        $customerName = $text('customer_name');
        if ($customerName === null) {
            return 'no customer name';
        }

        $authorizedText = $text('authorized_at');

        return new IssuedEcfExportRow(
            encf: $encf,
            issuedAt: $issuedAt->startOfDay(),
            authorizedAt: $authorizedText !== null ? MappedCsvService::date($authorizedText, $text('date_format')) : null,
            environment: EcfEnvironmentEnum::tryFrom((string) $text('environment')) ?? EcfEnvironmentEnum::PRODUCTION,
            status: $status,
            authorityStatus: $text('authority_status'),
            customerName: $customerName,
            customerTaxId: Str::normalizeTaxId($text('customer_tax_id')),
            subtotal: round($subtotal, 4),
            tax: round($tax, 4),
            total: round($total, 4),
            currency: strtoupper($text('currency') ?? 'DOP'),
            rate: $amount('rate') ?? 1.0,
            reference: $text('reference'),
            trackId: $text('track_id'),
            providerRef: $text('provider_ref'),
            rowNumber: $number,
        );
    }
}
