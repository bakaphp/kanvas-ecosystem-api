<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementFileAction;
use Kanvas\Scribe\Banking\Actions\SaveBankStatementMappingAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementIngestResult;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankStatementMapperResolverService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The per-bank CSV layouts are mapper data (resources/import-templates/bank-statement-*.json), not PHP.
 * Each seeded template is exercised end to end here: file → mapper → balance check → landed rows.
 */
final class BankStatementCsvTest extends BankStatementTestCase
{
    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount('4321', 'DOP', 'DR Checking');
    }

    /**
     * @return array<string, array{0: string, 1: ImportTemplateEnum}>
     */
    public static function seededBankExports(): array
    {
        return [
            'Banco Popular (amount + DB/CR type, preamble above the header)' => [
                "Banco Popular Dominicano\nCuenta: 000-4321 DOP\n\n"
                . "Fecha Posteo,No. Referencia,Descripción Corta,Tipo,Monto Transacción,Balance\n"
                . "02/06/2026,1001,DEPOSITO,CR,\"1,000.00\",\"6,000.00\"\n"
                . "05/06/2026,1002,PAGO CLARO,DB,300.00,\"5,700.00\"\n"
                . "06/06/2026,1003,COMISION,DB,25.00,\"5,675.00\"\n",
                ImportTemplateEnum::BANK_STATEMENT_POPULAR_CSV,
            ],
            'BHD (debit / credit columns, semicolons)' => [
                "Fecha;Referencia;Descripción;Débito;Crédito;Balance\n"
                . "02/06/2026;A1;DEPOSITO;;1000.00;6000.00\n"
                . "05/06/2026;A2;PAGO CLARO;300.00;;5700.00\n"
                . "06/06/2026;A3;COMISION;25.00;;5675.00\n",
                ImportTemplateEnum::BANK_STATEMENT_BHD_CSV,
            ],
            'Banreservas (withdrawal / deposit columns, newest first)' => [
                "Fecha Transacción,Documento,Concepto,Retiro,Depósito,Balance Disponible\n"
                . "06/06/2026,R3,COMISION,25.00,,5675.00\n"
                . "05/06/2026,R2,PAGO CLARO,300.00,,5700.00\n"
                . "02/06/2026,R1,DEPOSITO,,1000.00,6000.00\n",
                ImportTemplateEnum::BANK_STATEMENT_BANRESERVAS_CSV,
            ],
        ];
    }

    #[DataProvider('seededBankExports')]
    public function testEachSeededBankExportParsesThroughItsTemplate(string $csv, ImportTemplateEnum $template): void
    {
        $result = $this->ingestCsv($csv);

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome, (string) $result->reason);
        $this->assertSame(BankStatementSourceEnum::CSV, $result->statement->source);
        $this->assertSame(3, $result->newRows);
        $this->assertSame(5_000.00, $result->statement->opening_balance);
        $this->assertSame(5_675.00, $result->statement->closing_balance);

        $rows = BankTransaction::query()
            ->where('bank_account_id', $this->account->getId())
            ->orderBy('transaction_date')
            ->get();

        $this->assertSame(['2026-06-02', '2026-06-05', '2026-06-06'], $rows->map(fn ($t) => $t->transaction_date->toDateString())->all(), 'd/m/Y read as day first.');
        $this->assertSame(BankTransactionDirectionEnum::CREDIT, $rows[0]->direction);
        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $rows[1]->direction);
        $this->assertSame(300.00, $rows[1]->amount_native);

        $mapper = FilesystemMapper::query()->find($this->account->refresh()->metadata[BankStatementMapperResolverService::PINNED_MAPPER_KEY]);
        $this->assertSame($template->value, $mapper->configuration['template']['key'], 'The template was copied into a company mapper and pinned on the account.');
    }

    public function testAnUnknownLayoutReturnsTheHeaderAndLandsNothing(): void
    {
        $result = $this->ingestCsv("Date,Details,Out,In,Bal\n2026-06-02,Deposit,,1000,6000\n");

        $this->assertSame(BankStatementIngestOutcomeEnum::UNKNOWN_LAYOUT, $result->outcome);
        $this->assertSame(['Date', 'Details', 'Out', 'In', 'Bal'], $result->header);
        $this->assertSame([['2026-06-02', 'Deposit', '', '1000', '6000']], $result->sampleRows);
        $this->assertSame(0, BankTransaction::query()->where('bank_account_id', $this->account->getId())->count());
    }

    public function testAProposedMappingIsSavedPinnedAndUsed(): void
    {
        $csv = "Date,Details,Out,In,Bal\n2026-06-02,Deposit,,1000,6000\n2026-06-03,Fee,10,,5990\n";

        new SaveBankStatementMappingAction(
            $this->account,
            ['Date', 'Details', 'Out', 'In', 'Bal'],
            [
                'date' => 'Date',
                'description' => 'Details',
                'debit' => 'Out',
                'credit' => 'In',
                'running_balance' => 'Bal',
                'date_format' => '_Y-m-d',
            ],
            static::$cachedUser,
        )->execute();

        $result = $this->ingestCsv($csv);

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome, (string) $result->reason);
        $this->assertSame(2, $result->newRows);
        $this->assertSame(5_990.00, $result->statement->closing_balance);
    }

    public function testAMappingReadingAColumnTheFileDoesNotHaveIsRefused(): void
    {
        $this->expectExceptionMessage('not in the file header: Amount');

        new SaveBankStatementMappingAction(
            $this->account,
            ['Date', 'Details'],
            ['date' => 'Date', 'amount' => 'Amount'],
            static::$cachedUser,
        )->execute();
    }

    public function testACsvWithAnUnbalancedRowIsRejected(): void
    {
        $result = $this->ingestCsv(
            "Fecha;Referencia;Descripción;Débito;Crédito;Balance\n"
            . "02/06/2026;A1;DEPOSITO;;1000.00;6000.00\n"
            . "05/06/2026;A2;PAGO CLARO;300.00;;5600.00\n"
        );

        $this->assertSame(BankStatementIngestOutcomeEnum::REJECTED, $result->outcome);
        $this->assertSame(0, BankTransaction::query()->where('bank_account_id', $this->account->getId())->count());
    }

    public function testADateThatDoesNotExistRejectsTheStatementInsteadOfRollingOver(): void
    {
        $result = $this->ingestCsv(
            "Fecha;Referencia;Descripción;Débito;Crédito;Balance\n"
            . "02/06/2026;A1;DEPOSITO;;1000.00;6000.00\n"
            . "31/02/2026;A2;PAGO CLARO;300.00;;5700.00\n"
        );

        $this->assertSame(BankStatementIngestOutcomeEnum::REJECTED, $result->outcome);
        $this->assertStringContainsString('31/02/2026', (string) $result->reason);
        $this->assertSame(0, BankTransaction::query()->where('bank_account_id', $this->account->getId())->count());
    }

    private function ingestCsv(string $csv): BankStatementIngestResult
    {
        $this->fakeStatementFileContents($csv);

        return new IngestBankStatementFileAction(
            app: $this->kanvasApp,
            company: $this->company,
            file: $this->createFilesystemRow(extension: 'csv', fileType: 'csv'),
            bankAccount: $this->account,
            user: static::$cachedUser,
        )->execute();
    }
}
