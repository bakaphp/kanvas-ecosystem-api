<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\ReadSheetRangeAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Reads a range of cells from a Google Sheet the user shared, given its URL or bare id. */
#[AgentTool(name: 'Read Google Sheet', category: 'productivity')]
class ReadGoogleSheetTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'read_google_sheet',
            description: 'Reads rows/columns from a Google Sheet the user shared a link to (e.g. an invoice '
                . 'list). The sheet must already be shared as an Editor with this app\'s Google service account.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'sheet_url_or_id',
                type: PropertyType::STRING,
                description: 'The full Google Sheets URL the user shared (e.g. '
                    . '"https://docs.google.com/spreadsheets/d/1A_B_C_D_12345/edit#gid=0"), or a bare '
                    . 'spreadsheet id. Omit to use this app\'s default invoice-tracking sheet.',
                required: false,
            ),
            new ToolProperty(
                name: 'range',
                type: PropertyType::STRING,
                description: 'A1 notation range to read, e.g. "Sheet1!A1:E50" or "Invoices!A:D". Defaults to '
                    . '"A:Z" on the first sheet when omitted.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $sheet_url_or_id = null, ?string $range = null): array
    {
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        $range = $range !== null && trim($range) !== '' ? $range : 'A:Z';

        try {
            $rows = new ReadSheetRangeAction($this->app, $spreadsheetId, $range)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'read_failed',
                'message' => 'Could not read the sheet: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'spreadsheet_id' => $spreadsheetId,
            'range' => $range,
            'row_count' => count($rows),
            'rows' => $rows,
        ];
    }
}
