<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\AppendGoogleSheetRowsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ClearGoogleSheetRangeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\CreateGoogleSheetTabTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ReadGoogleSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\UpdateGoogleSheetCellTool;
use Tests\TestCase;

class GoogleSheetsToolsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * DEFAULT_INVOICE_SHEET lives on the app's custom-fields store, which persists outside the
     * ambient test transaction — save/restore explicitly so this test never clobbers a real
     * default sheet configured for this shared app.
     */
    private mixed $originalDefaultSheet = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultSheet = app(Apps::class)->get(ConfigurationEnum::DEFAULT_INVOICE_SHEET->value);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::DEFAULT_INVOICE_SHEET->value, $this->originalDefaultSheet);

        parent::tearDown();
    }

    public function test_read_google_sheet_rejects_a_url_that_is_not_a_sheet(): void
    {
        [$app, $company] = $this->context();

        $result = new ReadGoogleSheetTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(sheet_url_or_id: 'https://example.com/not-a-sheet');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_sheet_reference', $result['reason']);
    }

    public function test_write_google_sheet_rejects_a_url_that_is_not_a_sheet(): void
    {
        [$app, $company] = $this->context();

        $result = new AppendGoogleSheetRowsTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(sheet_url_or_id: 'not-a-sheet-url', range: 'Sheet1!A1', values: '[["1"]]');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_sheet_reference', $result['reason']);
    }

    public function test_write_google_sheet_requires_at_least_one_row(): void
    {
        [$app, $company] = $this->context();

        $result = new AppendGoogleSheetRowsTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(
                sheet_url_or_id: 'https://docs.google.com/spreadsheets/d/1A_B_C_D_12345/edit',
                range: 'Sheet1!A1',
                values: '[]',
            );

        $this->assertFalse($result['success']);
        $this->assertSame('values_required', $result['reason']);
    }

    public function test_write_google_sheet_rejects_a_non_json_values_string(): void
    {
        [$app, $company] = $this->context();

        $result = new AppendGoogleSheetRowsTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(
                sheet_url_or_id: 'https://docs.google.com/spreadsheets/d/1A_B_C_D_12345/edit',
                range: 'Sheet1!A1',
                values: 'not json',
            );

        $this->assertFalse($result['success']);
        $this->assertSame('values_required', $result['reason']);
    }

    public function test_update_google_sheet_cell_rejects_a_url_that_is_not_a_sheet(): void
    {
        [$app, $company] = $this->context();

        $result = new UpdateGoogleSheetCellTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(sheet_url_or_id: 'nope', range: 'Sheet1!D5', value: 'Approved');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_sheet_reference', $result['reason']);
    }

    public function test_clear_google_sheet_range_rejects_a_url_that_is_not_a_sheet(): void
    {
        [$app, $company] = $this->context();

        $result = new ClearGoogleSheetRangeTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(sheet_url_or_id: 'nope', range: 'Sheet1!A5:D5');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_sheet_reference', $result['reason']);
    }

    public function test_create_google_sheet_tab_rejects_a_url_that_is_not_a_sheet(): void
    {
        [$app, $company] = $this->context();

        $result = new CreateGoogleSheetTabTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(sheet_url_or_id: 'nope', title: 'Q3 Invoices');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_sheet_reference', $result['reason']);
    }

    public function test_read_google_sheet_reports_no_default_when_url_omitted_and_nothing_configured(): void
    {
        [$app, $company] = $this->context();
        $app->set(ConfigurationEnum::DEFAULT_INVOICE_SHEET->value, '');

        $result = new ReadGoogleSheetTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke();

        $this->assertFalse($result['success']);
        $this->assertSame('no_sheet_configured', $result['reason']);
    }

    public function test_resolve_spreadsheet_id_falls_back_to_the_configured_default_sheet(): void
    {
        [$app, $company] = $this->context();
        $app->set(
            ConfigurationEnum::DEFAULT_INVOICE_SHEET->value,
            'https://docs.google.com/spreadsheets/d/1A_B_C_D_12345/edit',
        );

        $tool = new ReadGoogleSheetTool()->withContext($app, $company, static::$cachedUser);
        $method = new \ReflectionMethod($tool, 'resolveSpreadsheetId');
        $method->setAccessible(true);

        $this->assertSame('1A_B_C_D_12345', $method->invoke($tool));
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        return [$app, $company];
    }
}
