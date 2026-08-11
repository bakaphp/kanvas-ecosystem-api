<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Google\Service\Sheets\ClearValuesRequest;

/** Wipes the values in a range without deleting the row/column itself — the safe counterpart to a structural row delete. */
class ClearSheetRangeAction extends AbstractSheetAction
{
    /**
     * @return array{cleared_range: string}
     */
    public function execute(): array
    {
        $result = $this->service()->spreadsheets_values->clear(
            $this->spreadsheetId,
            $this->range,
            new ClearValuesRequest(),
        );

        return ['cleared_range' => (string) $result->getClearedRange()];
    }
}
