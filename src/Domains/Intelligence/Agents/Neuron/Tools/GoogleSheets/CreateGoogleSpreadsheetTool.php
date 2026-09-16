<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Actions\CreateSpreadsheetAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesGoogleSheetsServiceForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Creates a new Google Sheets document in the agent's own connected Drive.
 *
 * Deliberately refuses the service-account path: a spreadsheet created by a service account is owned
 * by an account no person can open, so "created it" would be true and useless at the same time.
 */
#[AgentTool(name: 'Create Google Spreadsheet', category: 'productivity')]
class CreateGoogleSpreadsheetTool extends Tool
{
    use HasKanvasContext;
    use ResolvesGoogleSheetsServiceForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_google_spreadsheet',
            description: 'Creates a NEW, empty Google Sheets document in the Google account this agent is '
                . 'connected to, and returns its id and URL. Use create_google_sheet_tab instead to add a tab '
                . 'to a document that already exists, and write_google_sheet to fill the new document with rows.',
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
                name: 'title',
                type: PropertyType::STRING,
                description: 'The name for the new spreadsheet, e.g. "Q3 Invoices". Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $title): array
    {
        $title = trim($title);

        if ($title === '') {
            return [
                'success' => false,
                'reason' => 'title_required',
                'message' => 'A title is required — ask the user what the new spreadsheet should be called.',
            ];
        }

        $service = $this->sheetsServiceForAgent();

        if ($service === null) {
            return [
                'success' => false,
                'reason' => 'no_google_account_connected',
                'message' => 'Creating a spreadsheet needs this agent\'s own Google account, and it has none '
                    . 'connected. Tell the user to connect Google Sheets for this agent, then try again.',
            ];
        }

        try {
            $result = new CreateSpreadsheetAction($this->app, $title, $service)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'create_failed',
                'message' => 'Could not create the spreadsheet: ' . $e->getMessage(),
            ];
        }

        return ['success' => true, ...$result];
    }
}
