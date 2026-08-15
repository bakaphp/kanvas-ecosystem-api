<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

/** Reads a range's values as a list of rows (each row a list of cell values, left-to-right, top-to-bottom). */
class ReadSheetRangeAction extends AbstractSheetAction
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public function execute(): array
    {
        $response = $this->service()->spreadsheets_values->get($this->spreadsheetId, $this->range);

        return $response->getValues() ?? [];
    }
}
