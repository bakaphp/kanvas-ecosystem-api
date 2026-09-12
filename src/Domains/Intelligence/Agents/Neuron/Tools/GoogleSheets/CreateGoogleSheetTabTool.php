<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\CreateSheetTabAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesGoogleSheetsServiceForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Adds a tab to an existing Google Sheets document, e.g. a new "Q3" tab alongside existing ones.
 *
 * The name reads like a broader capability than it has, so the description carries the negative fact
 * explicitly: this creates a TAB, never a spreadsheet. Without it the closest-named tool for "create
 * a new Google Sheet" is this one, and — because every other sheets tool falls back to the app's
 * default invoice tracker — the plausible outcome was a surprise tab on a live AP document.
 */
#[AgentTool(name: 'Create Google Sheet Tab', category: 'productivity')]
class CreateGoogleSheetTabTool extends Tool
{
    use HasKanvasContext;
    use ResolvesGoogleSheetsServiceForTool;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_google_sheet_tab',
            description: 'Adds a new tab (worksheet) to an EXISTING Google Sheets document — e.g. a "Q3 Invoices" '
                . 'tab alongside the ones already there. This does NOT create a new spreadsheet: use '
                . 'create_google_spreadsheet for that. Requires the sheet link — unlike the other sheets tools it '
                . 'will not fall back to a default document. Does not touch or remove any existing tab. Works on '
                . 'any sheet the Google account this agent is connected to can open; where the app uses a shared '
                . 'service account instead, the sheet must be shared with that account as an Editor.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            // Deliberately not schema-required: an omitted reference has to reach __invoke() so the
            // refusal can point at create_google_spreadsheet. A schema error would reject the call
            // without ever teaching the model which tool it should have reached for.
            new ToolProperty(
                name: 'sheet_url_or_id',
                type: PropertyType::STRING,
                description: 'The full Google Sheets URL of the EXISTING document to add a tab to, or a bare '
                    . 'spreadsheet id. You must supply this — this tool does not fall back to a default document.',
                required: false,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'The name for the new tab, e.g. "Q3 Invoices". Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $title, ?string $sheet_url_or_id = null): array
    {
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id, allowDefault: false);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        try {
            $result = new CreateSheetTabAction(
                $this->app,
                $spreadsheetId,
                $title,
                $this->sheetsServiceForAgent(),
            )->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'create_tab_failed',
                'message' => 'Could not create the sheet tab: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'spreadsheet_id' => $spreadsheetId,
            ...$result,
        ];
    }
}
