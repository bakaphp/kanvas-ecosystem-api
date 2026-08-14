<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Sheets\AddSheetRequest;
use Google\Service\Sheets as GoogleSheetsService;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsBatchRequest;
use Google\Service\Sheets\SheetProperties;

/** Adds a brand-new sheet/tab to an existing spreadsheet document without touching any other tab. */
class CreateSheetTabAction extends AbstractSheetAction
{
    public function __construct(
        AppInterface $app,
        string $spreadsheetId,
        protected string $sheetTitle,
        ?GoogleSheetsService $service = null,
    ) {
        parent::__construct($app, $spreadsheetId, service: $service);
    }

    /**
     * @return array{sheet_id: int, title: string}
     */
    public function execute(): array
    {
        $response = $this->service()->spreadsheets->batchUpdate(
            $this->spreadsheetId,
            new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new SheetsBatchRequest([
                        'addSheet' => new AddSheetRequest([
                            'properties' => new SheetProperties(['title' => $this->sheetTitle]),
                        ]),
                    ]),
                ],
            ]),
        );

        $addedSheetProperties = $response->getReplies()[0]->getAddSheet()->getProperties();

        return [
            'sheet_id' => (int) $addedSheetProperties->getSheetId(),
            'title' => (string) $addedSheetProperties->getTitle(),
        ];
    }
}
