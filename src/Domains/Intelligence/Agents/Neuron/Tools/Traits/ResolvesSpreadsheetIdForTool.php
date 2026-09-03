<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Connectors\GoogleSheets\Support\SpreadsheetUrlParser;

/** Extracts a spreadsheet id from a URL/id a tool received, optionally falling back to the app's default invoice-tracking sheet when none is given. Returns the id, or an LLM-facing error array the caller merges into its own response shape. */
trait ResolvesSpreadsheetIdForTool
{
    /**
     * @param bool $allowDefault Whether an omitted reference may fall back to the app's default
     *        invoice-tracking sheet. Pass false from any tool that changes a document's structure:
     *        the default exists so "log this invoice" works without pasting a link every time, which
     *        is a safe guess for an append and an unsafe one for a structural write to a live doc.
     *
     * @return string|array{reason: string, message: string}
     */
    protected function resolveSpreadsheetId(?string $sheetUrlOrId = null, bool $allowDefault = true): string|array
    {
        $hasExplicitReference = $sheetUrlOrId !== null && trim($sheetUrlOrId) !== '';

        if (! $hasExplicitReference && ! $allowDefault) {
            return [
                'reason' => 'sheet_reference_required',
                'message' => 'This tool needs a link to an EXISTING Google Sheets document and none was given. '
                    . 'It does NOT create a new spreadsheet, and no tool does. If the user asked for a new '
                    . 'sheet, tell them that and ask which existing document to work in.',
            ];
        }

        $sheetUrlOrId = $hasExplicitReference
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
