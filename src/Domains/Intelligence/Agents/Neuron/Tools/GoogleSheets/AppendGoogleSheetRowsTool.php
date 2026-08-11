<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\AppendSheetRowsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Appends new rows to a Google Sheet the user shared, e.g. adding invoices to a shared tracking list. */
#[AgentTool(name: 'Write Google Sheet Rows', category: 'productivity')]
class AppendGoogleSheetRowsTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'write_google_sheet',
            description: 'Appends one or more new rows to the end of a Google Sheet the user shared a link to. '
                . 'Never overwrites existing rows — use update_google_sheet_cell for that. The sheet must already '
                . 'be shared as an Editor with this app\'s Google service account.',
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
                description: 'A1 notation range identifying the sheet/table to append after, e.g. "Sheet1!A1" or '
                    . '"Invoices!A:D". Only the sheet name matters for an append — Google finds the first empty '
                    . 'row on its own.',
                required: true,
            ),
            new ToolProperty(
                name: 'values',
                type: PropertyType::ARRAY,
                description: 'Array of rows to append, each row itself an array of cell values in column order, '
                    . 'e.g. [["1498", "Windwalk Games Corp", 250.00, "Pending"]]. At least one row is required.',
                required: true,
            ),
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $values
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $sheet_url_or_id, string $range, array $values): array
    {
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        if ($values === []) {
            return [
                'success' => false,
                'reason' => 'values_required',
                'message' => 'At least one row (an array of cell values) is required.',
            ];
        }

        try {
            $result = new AppendSheetRowsAction($this->app, $spreadsheetId, $range, $values)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'write_failed',
                'message' => 'Could not write to the sheet: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'spreadsheet_id' => $spreadsheetId,
            ...$result,
        ];
    }
}
