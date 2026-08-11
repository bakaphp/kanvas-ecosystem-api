<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\GoogleSheets\Support\SpreadsheetUrlParser;

/** Extracts a spreadsheet id from a URL/id a tool received. Returns the id, or an LLM-facing error array the caller merges into its own response shape. */
trait ResolvesSpreadsheetIdForTool
{
    /**
     * @return string|array{reason: string, message: string}
     */
    protected function resolveSpreadsheetId(string $sheetUrlOrId): string|array
    {
        $spreadsheetId = SpreadsheetUrlParser::extractId($sheetUrlOrId);

        if ($spreadsheetId === null) {
            return [
                'reason' => 'invalid_sheet_reference',
                'message' => "\"{$sheetUrlOrId}\" doesn't look like a Google Sheets URL or id.",
            ];
        }

        return $spreadsheetId;
    }
}
