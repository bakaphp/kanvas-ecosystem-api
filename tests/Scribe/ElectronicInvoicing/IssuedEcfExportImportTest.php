<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileContentsReaderService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ImportIssuedEcfExportTool;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ImportIssuedEcfExportAction;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfImportResult;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfRowImportResult;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Services\IssuedEcfExportParserService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Scribe\ScribeTestCase;

/**
 * e-CFs issued in the WebPOS portal before Kanvas come in from the portal's CSV export: issued invoices with
 * their fiscal rows and journal entries, rejected numbers recorded as burned, customers resolved by RNC then
 * name, and nothing guessed when the two disagree.
 */
class IssuedEcfExportImportTest extends ScribeTestCase
{
    private const string SKILLS = 'SKILLS, S.R.L.';
    private const string TOO = 'TOO CORPORATION AMERICAS, LLC.';

    protected function afterScribeSetUp(): void
    {
        // The regime is the provider's country, else the company's; no provider here, so the company is Dominican.
        Companies::query()->whereKey($this->company->getId())->update(['country_code' => 'DO']);
        $this->company->refresh();
    }

    public function test_the_export_parses_through_the_template(): void
    {
        ['rows' => $rows, 'unreadable' => $unreadable] = new IssuedEcfExportParserService()->parse($this->export());

        $this->assertSame(['Row 4: E310000000009 is marked cancelled in the provider; not imported'], $unreadable);
        $this->assertCount(3, $rows);
        $this->assertSame('E310000000008', $rows[0]->encf);
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $rows[0]->status);
        $this->assertSame(EcfEnvironmentEnum::PRODUCTION, $rows[0]->environment);
        $this->assertSame('2026-06-05', $rows[0]->issuedAt->toDateString());
        $this->assertSame(34998.0, $rows[0]->subtotal);
        $this->assertSame(6299.64, $rows[0]->tax);
        $this->assertSame(41297.64, $rows[0]->total);
        $this->assertSame('130434344', $rows[0]->customerTaxId);
        $this->assertSame(FiscalDocumentStatusEnum::REJECTED, $rows[1]->status);
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED_WITH_OBSERVATIONS, $rows[2]->status);
        $this->assertSame('USD', $rows[2]->currency);
        $this->assertSame(54.91, $rows[2]->rate);
    }

    public function test_the_export_lands_as_issued_invoices_with_fiscal_rows_and_journal_entries_once(): void
    {
        $result = $this->import();

        $this->assertSame(IssuedEcfImportResult::IMPORTED, $result->outcome);
        $this->assertCount(2, $result->with(IssuedEcfRowImportResult::CREATED), json_encode($result->toArray()));
        $this->assertCount(1, $result->with(IssuedEcfRowImportResult::REJECTED_RECORDED));
        $this->assertSame(2, $result->toArray()['organizations_created']);
        $this->assertSame(['DOP' => 41297.64, 'USD' => 4800.0], $result->toArray()['open_balance_imported']);

        $accepted = Invoice::query()->where('companies_id', $this->company->getId())->where('invoice_number', 'E310000000008')->firstOrFail();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $accepted->document_status);
        $this->assertSame('2026-06-05', $accepted->issued_date->toDateString());
        $this->assertSame(41297.64, (float) $accepted->total_native);
        $this->assertSame(41297.64, (float) $accepted->balance_due_native, 'the export carries no payments');
        $this->assertSame('130434344', Organization::query()->findOrFail($accepted->customer_organization_id)->tax_id);

        $fiscal = FiscalDocument::query()->where('entity_id', $accepted->getId())->firstOrFail();
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->status);
        $this->assertSame('E310000000008', $fiscal->authority_id);
        $this->assertSame('webpos', $fiscal->provider);
        $this->assertTrue((bool) $fiscal->authority_number_consumed);
        $this->assertNull($fiscal->issuer_tax_id, 'no issuer RNC configured: the column stays null, only the identity key gets a placeholder');

        $je = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $accepted->getId())
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->firstOrFail();
        $this->assertSame('2026-06-05', $je->posted_at->toDateString(), 'posted in the invoice\'s own period');

        $rejected = Invoice::query()->where('companies_id', $this->company->getId())->where('invoice_number', 'E310000000006')->firstOrFail();
        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $rejected->document_status);
        $this->assertSame(FiscalDocumentStatusEnum::REJECTED, FiscalDocument::query()->where('entity_id', $rejected->getId())->firstOrFail()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $rejected->getId())->count(), 'a rejected number books nothing');

        $usd = Invoice::query()->where('companies_id', $this->company->getId())->where('invoice_number', 'E320000000003')->firstOrFail();
        $this->assertSame('USD', $usd->currency);
        $this->assertSame(54.91, (float) $usd->fx_rate_to_base);
        $usdFiscal = FiscalDocument::query()->where('entity_id', $usd->getId())->firstOrFail();
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED_WITH_OBSERVATIONS, $usdFiscal->status);
        $this->assertTrue((bool) $usdFiscal->needs_review, 'the export does not say what the observations were');

        $again = $this->import();
        $this->assertCount(3, $again->with(IssuedEcfRowImportResult::SKIPPED_EXISTING), 'the same export twice changes nothing');
        $this->assertSame(0, $again->toArray()['organizations_created']);
    }

    public function test_a_customer_with_a_different_tax_id_is_left_for_a_person_and_a_missing_one_is_filled(): void
    {
        $skills = $this->seedTestOrganization(self::SKILLS);
        $skills->tax_id = '999999999';
        $skills->saveOrFail();
        $too = $this->seedTestOrganization(self::TOO);

        $result = $this->import();
        $review = $result->with(IssuedEcfRowImportResult::NEEDS_REVIEW);

        $this->assertCount(2, $review, 'both SKILLS rows wait on the tax id conflict');
        $this->assertStringContainsString('999999999', $review[0]->message);
        $this->assertSame(0, Invoice::query()->where('companies_id', $this->company->getId())->where('invoice_number', 'like', 'E31%')->count());
        $this->assertSame('999999999', $skills->refresh()->tax_id, 'never overwritten');

        $created = $result->with(IssuedEcfRowImportResult::CREATED);
        $this->assertCount(1, $created);
        $this->assertSame('tax_id_written', $created[0]->organizationAction);
        $this->assertSame('832056446', $too->refresh()->tax_id);
    }

    public function test_a_dry_run_writes_nothing_and_says_what_would_happen(): void
    {
        $result = $this->import(dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertCount(2, $result->with(IssuedEcfRowImportResult::CREATED));
        $this->assertSame('would_create', $result->with(IssuedEcfRowImportResult::CREATED)[0]->organizationAction);
        $this->assertSame(0, Invoice::query()->where('companies_id', $this->company->getId())->where('invoice_number', 'like', 'E3%')->count());
        $this->assertSame(0, Organization::query()->where('companies_id', $this->company->getId())->where('name', self::SKILLS)->count());
    }

    public function test_the_tool_imports_a_stored_export_and_refuses_another_layout(): void
    {
        $this->fakeFileContents($this->export());
        $file = $this->createFilesystemRow(extension: 'csv', fileType: 'csv');
        $tool = new ImportIssuedEcfExportTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $preview = $tool->__invoke(filesystem_id: $file->getId(), dry_run: true);
        $this->assertTrue($preview['success'], json_encode($preview));
        $this->assertFalse($preview['imported']);
        $this->assertCount(2, $preview['created']);

        $imported = $tool->__invoke(filesystem_id: $file->getId());
        $this->assertTrue($imported['imported']);
        $this->assertCount(1, $imported['rejected_recorded']);

        $this->fakeFileContents("Fecha Posteo,Monto Transacción,Tipo\n2026-06-02,100.00,CR\n");
        $other = $tool->__invoke(filesystem_id: $this->createFilesystemRow(extension: 'csv', fileType: 'csv')->getId());
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $other['outcome']);
        $this->assertFalse($other['imported']);
        $this->assertContains('Fecha Posteo', $other['header']);
    }

    private function import(bool $dryRun = false): IssuedEcfImportResult
    {
        return new ImportIssuedEcfExportAction(
            app: $this->kanvasApp,
            company: $this->company,
            contents: $this->export(),
            user: static::$cachedUser,
            dryRun: $dryRun,
        )->execute();
    }

    private function export(): string
    {
        // The real export of 2026-11-09, dates moved into the open test period: no emission date, amounts with
        // currency signs and trailing spaces, AMBIENTE 1, ESTADO OTRO for the conditional acceptance.
        $header = ImportTemplateEnum::WEBPOS_ISSUED_ECF_CSV->template()->fileHeader;
        $rows = [
            ['6/5/26 12:29', '1', 'F', 'E310000000008', 'E310000000008', self::SKILLS, '130434344', '$34,998.00 ', '$6,299.64 ', '$41,297.64 ', 'DOP', '1', 'TRUE', 'AUTORIZADO', '6/5/26 12:29', 'TRUE', '1', '1', 'E310000000008', '', '31', 'FALSE'],
            ['6/5/26 12:08', '1', 'F', 'E310000000006', 'E310000000006', self::SKILLS, '130434344', '$34,998.00 ', '$6,299.64 ', '$41,297.64 ', 'DOP', '1', 'TRUE', 'RECHAZADO', '6/5/26 12:08', 'FALSE', '1', '1', 'E310000000006', '', '31', 'FALSE'],
            ['6/7/26 10:54', '1', 'F', 'E320000000003', 'E320000000003', self::TOO, '832056446', '$4,800.00 ', '$0.00 ', '$4,800.00 ', 'USD', '54.91', 'TRUE', 'OTRO', '6/7/26 10:54', 'TRUE', '1', '1', 'E320000000003', '', '32', 'FALSE'],
            ['6/8/26 9:00', '1', 'F', 'E310000000009', 'E310000000009', self::SKILLS, '130434344', '$100.00 ', '$18.00 ', '$118.00 ', 'DOP', '1', 'TRUE', 'AUTORIZADO', '6/8/26 9:00', 'TRUE', '1', '1', 'E310000000009', '', '31', 'TRUE'],
        ];
        $csv = fn (array $cells): string => implode(',', array_map(fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"', $cells));

        return implode("\n", [$csv($header), ...array_map($csv, $rows)]) . "\n";
    }

    private function fakeFileContents(string $contents): void
    {
        $this->app->instance(FileContentsReaderService::class, new class ($contents) extends FileContentsReaderService {
            public function __construct(private readonly string $contents)
            {
            }

            public function read(Filesystem $file): string
            {
                return $this->contents;
            }
        });
    }
}
