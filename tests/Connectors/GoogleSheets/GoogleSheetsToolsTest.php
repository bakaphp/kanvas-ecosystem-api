<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\AppendGoogleSheetRowsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ClearGoogleSheetRangeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\CreateGoogleSheetTabTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ReadGoogleSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\UpdateGoogleSheetCellTool;
use Tests\TestCase;

class GoogleSheetsToolsTest extends TestCase
{
    use DatabaseTransactions;

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
