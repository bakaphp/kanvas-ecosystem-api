<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Google\Service\Sheets as GoogleSheetsService;
use Google\Service\Sheets\AddSheetResponse;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Google\Service\Sheets\Resource\Spreadsheets;
use Google\Service\Sheets\Resource\SpreadsheetsValues;
use Google\Service\Sheets\Response as SheetsBatchResponse;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\UpdateValuesResponse;
use Google\Service\Sheets\ValueRange;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\GoogleSheets\Actions\AppendSheetRowsAction;
use Kanvas\Connectors\GoogleSheets\Actions\ClearSheetRangeAction;
use Kanvas\Connectors\GoogleSheets\Actions\CreateSheetTabAction;
use Kanvas\Connectors\GoogleSheets\Actions\ReadSheetRangeAction;
use Kanvas\Connectors\GoogleSheets\Actions\UpdateSheetRangeAction;
use Mockery;
use Tests\TestCase;

class GoogleSheetsActionsTest extends TestCase
{
    public function test_read_sheet_range_returns_the_values_from_the_response(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('get')
            ->once()
            ->with('SHEET_ID', 'Invoices!A:D')
            ->andReturn(new ValueRange(['values' => [
                ['ID Factura', 'Proveedor', 'Monto', 'Estado'],
                ['1498', 'Windwalk Games Corp', '250.00', 'Pending'],
            ]]));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $rows = new ReadSheetRangeAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            range: 'Invoices!A:D',
            service: $service,
        )->execute();

        $this->assertCount(2, $rows);
        $this->assertSame(['1498', 'Windwalk Games Corp', '250.00', 'Pending'], $rows[1]);
    }

    public function test_read_sheet_range_returns_an_empty_array_for_an_empty_range(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('get')->once()->andReturn(new ValueRange());

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $rows = new ReadSheetRangeAction(app(Apps::class), 'SHEET_ID', 'Sheet1!A:Z', $service)->execute();

        $this->assertSame([], $rows);
    }

    public function test_append_sheet_rows_sends_the_rows_and_returns_the_updated_range(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('append')
            ->once()
            ->with(
                'SHEET_ID',
                'Invoices!A1',
                Mockery::on(fn (ValueRange $body) => $body->getValues() === [['1498', 'Windwalk Games Corp', 250.0, 'Pending']]),
                ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'],
            )
            ->andReturn(new AppendValuesResponse([
                'updates' => new UpdateValuesResponse(['updatedRange' => 'Invoices!A5:D5', 'updatedRows' => 1]),
            ]));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $result = new AppendSheetRowsAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            range: 'Invoices!A1',
            rows: [['1498', 'Windwalk Games Corp', 250.0, 'Pending']],
            service: $service,
        )->execute();

        $this->assertSame('Invoices!A5:D5', $result['updated_range']);
        $this->assertSame(1, $result['updated_rows']);
    }

    public function test_update_sheet_range_overwrites_the_target_cell(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('update')
            ->once()
            ->with(
                'SHEET_ID',
                'Invoices!D5',
                Mockery::on(fn (ValueRange $body) => $body->getValues() === [['Approved']]),
                ['valueInputOption' => 'USER_ENTERED'],
            )
            ->andReturn(new UpdateValuesResponse(['updatedRange' => 'Invoices!D5', 'updatedCells' => 1]));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $result = new UpdateSheetRangeAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            range: 'Invoices!D5',
            values: [['Approved']],
            service: $service,
        )->execute();

        $this->assertSame('Invoices!D5', $result['updated_range']);
        $this->assertSame(1, $result['updated_cells']);
    }

    public function test_update_sheet_range_passes_a_formula_value_through_unmodified(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('update')
            ->once()
            ->with(
                'SHEET_ID',
                'Invoices!E2',
                Mockery::on(fn (ValueRange $body) => $body->getValues() === [['=SUM(C2:C10)']]),
                ['valueInputOption' => 'USER_ENTERED'],
            )
            ->andReturn(new UpdateValuesResponse(['updatedRange' => 'Invoices!E2', 'updatedCells' => 1]));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $result = new UpdateSheetRangeAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            range: 'Invoices!E2',
            values: [['=SUM(C2:C10)']],
            service: $service,
        )->execute();

        $this->assertSame(1, $result['updated_cells']);
    }

    public function test_clear_sheet_range_wipes_the_target_range_without_deleting_it(): void
    {
        $valuesResource = Mockery::mock(SpreadsheetsValues::class);
        $valuesResource->shouldReceive('clear')
            ->once()
            ->with('SHEET_ID', 'Invoices!A5:D5', Mockery::type('Google\Service\Sheets\ClearValuesRequest'))
            ->andReturn(new ClearValuesResponse(['clearedRange' => 'Invoices!A5:D5']));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets_values = $valuesResource;

        $result = new ClearSheetRangeAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            range: 'Invoices!A5:D5',
            service: $service,
        )->execute();

        $this->assertSame('Invoices!A5:D5', $result['cleared_range']);
    }

    public function test_create_sheet_tab_adds_a_new_tab_and_returns_its_id_and_title(): void
    {
        $spreadsheetsResource = Mockery::mock(Spreadsheets::class);
        $spreadsheetsResource->shouldReceive('batchUpdate')
            ->once()
            ->with(
                'SHEET_ID',
                Mockery::on(fn (BatchUpdateSpreadsheetRequest $body) => $body->getRequests()[0]->getAddSheet()
                    ->getProperties()->getTitle() === 'Q3 Invoices'),
            )
            ->andReturn(new BatchUpdateSpreadsheetResponse([
                'replies' => [
                    new SheetsBatchResponse([
                        'addSheet' => new AddSheetResponse([
                            'properties' => new SheetProperties(['sheetId' => 987654321, 'title' => 'Q3 Invoices']),
                        ]),
                    ]),
                ],
            ]));

        $service = Mockery::mock(GoogleSheetsService::class);
        $service->spreadsheets = $spreadsheetsResource;

        $result = new CreateSheetTabAction(
            app: app(Apps::class),
            spreadsheetId: 'SHEET_ID',
            sheetTitle: 'Q3 Invoices',
            service: $service,
        )->execute();

        $this->assertSame(987654321, $result['sheet_id']);
        $this->assertSame('Q3 Invoices', $result['title']);
    }
}
