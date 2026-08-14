<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Connectors\GoogleSheets\Support\SpreadsheetUrlParser;

/** Extracts a spreadsheet id from a URL/id a tool received, falling back to the app's default invoice-tracking sheet when none is given. Returns the id, or an LLM-facing error array the caller merges into its own response shape. */
trait ResolvesSpreadsheetIdForTool
{
    /**
     * @return string|array{reason: string, message: string}
     */
    protected function resolveSpreadsheetId(?string $sheetUrlOrId = null): string|array
    {
        $sheetUrlOrId = $sheetUrlOrId !== null && trim($sheetUrlOrId) !== ''
            ? $sheetUrlOrId
            : $this->app->get(ConfigurationEnum::DEFAULT_INVOICE_SHEET->value);

        if (empty($sheetUrlOrId)) {
            return [
                'reason' => 'no_sheet_configured',
                'message' => 'No sheet_url_or_id was given, and this app has no default invoice-tracking sheet '
                    . 'configured.',
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
