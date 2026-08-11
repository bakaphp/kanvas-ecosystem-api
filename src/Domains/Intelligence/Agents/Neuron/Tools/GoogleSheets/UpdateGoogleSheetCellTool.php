<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\UpdateSheetRangeAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Overwrites a specific cell (or range) already on a Google Sheet, e.g. flipping an invoice's status to "Approved". */
#[AgentTool(name: 'Update Google Sheet Cell', category: 'productivity')]
class UpdateGoogleSheetCellTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'update_google_sheet_cell',
            description: 'Overwrites a specific cell (or small range) on a Google Sheet the user shared a link '
                . 'to — e.g. changing an invoice row\'s status column to "Approved". Use write_google_sheet '
                . 'instead when adding brand-new rows. The sheet must already be shared as an Editor with this '
                . 'app\'s Google service account.',
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
                description: 'The full Google Sheets URL the user shared, or a bare spreadsheet id. Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'range',
                type: PropertyType::STRING,
                description: 'A1 notation reference to the exact cell to overwrite, e.g. "Sheet1!D5" or '
                    . '"Invoices!C12". Always required — never guess the row without confirming it first via '
                    . 'read_google_sheet.',
                required: true,
            ),
            new ToolProperty(
                name: 'value',
                type: PropertyType::STRING,
                description: 'The new value for that cell, e.g. "Approved" or "250.00".',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $sheet_url_or_id, string $range, string $value): array
    {
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        try {
            $result = new UpdateSheetRangeAction($this->app, $spreadsheetId, $range, [[$value]])->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'update_failed',
                'message' => 'Could not update the sheet: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'spreadsheet_id' => $spreadsheetId,
            ...$result,
        ];
    }
}
