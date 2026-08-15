<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Sheets as GoogleSheetsService;
use Google\Service\Sheets\ValueRange;

/** Overwrites the values already in a range in place (e.g. a single cell like "Sheet1!D5") — the counterpart to AppendSheetRowsAction. */
class UpdateSheetRangeAction extends AbstractSheetAction
{
    /**
     * @param array<int, array<int, mixed>> $values
     */
    public function __construct(
        AppInterface $app,
        string $spreadsheetId,
        string $range,
        protected array $values,
        ?GoogleSheetsService $service = null,
    ) {
        parent::__construct($app, $spreadsheetId, $range, $service);
    }

    /**
     * @return array{updated_range: string, updated_cells: int}
     */
    public function execute(): array
    {
        $result = $this->service()->spreadsheets_values->update(
            $this->spreadsheetId,
            $this->range,
            new ValueRange(['values' => $this->values]),
            ['valueInputOption' => 'USER_ENTERED'],
        );

        return [
            'updated_range' => (string) $result->getUpdatedRange(),
            'updated_cells' => (int) $result->getUpdatedCells(),
        ];
    }
}
