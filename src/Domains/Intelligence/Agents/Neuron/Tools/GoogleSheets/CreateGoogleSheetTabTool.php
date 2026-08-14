<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\CreateSheetTabAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSpreadsheetIdForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Adds a brand-new sheet/tab to an existing Google Sheets document, e.g. a new "Q3" tab alongside existing ones. */
#[AgentTool(name: 'Create Google Sheet Tab', category: 'productivity')]
class CreateGoogleSheetTabTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSpreadsheetIdForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_google_sheet_tab',
            description: 'Creates a brand-new sheet/tab inside an existing Google Sheets document the user shared '
                . 'a link to — e.g. adding a new "Q3 Invoices" tab. Does not touch or remove any existing tab. '
                . 'The sheet must already be shared as an Editor with this app\'s Google service account.',
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
        $spreadsheetId = $this->resolveSpreadsheetId($sheet_url_or_id);

        if (is_array($spreadsheetId)) {
            return ['success' => false, ...$spreadsheetId];
        }

        try {
            $result = new CreateSheetTabAction($this->app, $spreadsheetId, $title)->execute();
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
