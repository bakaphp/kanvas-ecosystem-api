<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\GoogleSheets\Support\SpreadsheetUrlParser;

/** Extracts a spreadsheet id from the URL/id a tool received. Returns the id, or an LLM-facing error array the caller merges into its own response shape. */
trait ResolvesSpreadsheetIdForTool
{
    /**
     * @return string|array{reason: string, message: string}
     */
    protected function resolveSpreadsheetId(?string $sheetUrlOrId = null): string|array
    {
        if ($sheetUrlOrId === null || trim($sheetUrlOrId) === '') {
            return [
                'reason' => 'sheet_reference_required',
                'message' => 'This tool needs a link to an EXISTING Google Sheets document and none was given. '
                    . 'Ask the user for it. It does NOT create a new spreadsheet — use create_google_spreadsheet for that.',
            ];
        }

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
