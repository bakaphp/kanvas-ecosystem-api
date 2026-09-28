<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Sheets as GoogleSheetsService;
use Google\Service\Sheets\ValueRange;

/** Appends one or more new rows after the last row of data in the range's sheet — never overwrites existing rows. */
class AppendSheetRowsAction extends AbstractSheetAction
{
    /**
     * @param array<int, array<int, mixed>> $rows
     */
    public function __construct(
        AppInterface $app,
        string $spreadsheetId,
        string $range,
        protected array $rows,
        ?GoogleSheetsService $service = null,
    ) {
        parent::__construct($app, $spreadsheetId, $range, $service);
    }

    /**
     * @return array{updated_range: string, updated_rows: int}
     */
    public function execute(): array
    {
        $updates = $this->service()->spreadsheets_values->append(
            $this->spreadsheetId,
            $this->range,
            new ValueRange(['values' => $this->rows]),
            ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'],
        )->getUpdates();

        return [
            'updated_range' => (string) $updates->getUpdatedRange(),
            'updated_rows' => (int) $updates->getUpdatedRows(),
        ];
    }
}
