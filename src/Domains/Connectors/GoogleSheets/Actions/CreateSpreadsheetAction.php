<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Sheets as GoogleSheetsService;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\SpreadsheetProperties;

/** Creates a brand-new spreadsheet document, owned by whichever Google account the service is signed in as. */
class CreateSpreadsheetAction extends AbstractSheetAction
{
    public function __construct(AppInterface $app, protected string $title, ?GoogleSheetsService $service = null)
    {
        // The one action with no spreadsheet to address — it is what produces the id.
        parent::__construct($app, spreadsheetId: '', service: $service);
    }

    /**
     * @return array{spreadsheet_id: string, title: string, url: string}
     */
    public function execute(): array
    {
        $spreadsheet = $this->service()->spreadsheets->create(
            new Spreadsheet(['properties' => new SpreadsheetProperties(['title' => $this->title])])
        );

        return [
            'spreadsheet_id' => (string) $spreadsheet->getSpreadsheetId(),
            'title' => (string) $spreadsheet->getProperties()->getTitle(),
            'url' => (string) $spreadsheet->getSpreadsheetUrl(),
        ];
    }
}
