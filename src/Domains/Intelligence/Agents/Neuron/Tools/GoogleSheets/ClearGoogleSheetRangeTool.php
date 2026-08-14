<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\ClearSheetRangeAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Wipes the contents of a cell/row/range on a Google Sheet without deleting the row itself, e.g. clearing a cancelled invoice's data. */
#[AgentTool(name: 'Clear Google Sheet Range', category: 'productivity')]
class ClearGoogleSheetRangeTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'clear_google_sheet_range',
            description: 'Wipes the contents of a cell, row, or range on a Google Sheet the user shared a link to '
                . '— e.g. clearing out a cancelled invoice row. This does NOT delete the row itself, only its '
                . 'values; the row stays in place and other rows are never shifted. The sheet must already be '
                . 'shared as an Editor with this app\'s Google service account.',
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
                description: 'The full Google Sheets URL the user shared, or a bare spreadsheet id. Omit to use '
                    . 'this app\'s default invoice-tracking sheet.',
                required: false,
            ),
            new ToolProperty(
                name: 'range',
                type: PropertyType::STRING,
                description: 'A1 notation reference to the cell/row/range to clear, e.g. "Sheet1!A5:D5" or '
                    . '"Invoices!C12". Always required — never guess the row without confirming it first via '
                    . 'read_google_sheet.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $range, ?string $sheet_url_or_id = null): array
    {
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        try {
            $result = new ClearSheetRangeAction($this->app, $spreadsheetId, $range)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'clear_failed',
                'message' => 'Could not clear the sheet range: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'spreadsheet_id' => $spreadsheetId,
            ...$result,
        ];
    }
}
