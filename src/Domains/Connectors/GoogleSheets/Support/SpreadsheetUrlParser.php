<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Support;

class SpreadsheetUrlParser
{
    /** Accepts a full Sheets URL (any tab/gid) or a bare spreadsheet id; returns null when neither shape matches. */
    public static function extractId(string $urlOrId): ?string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $urlOrId, $matches)) {
            return $matches[1];
        }

        if (preg_match('#^[a-zA-Z0-9_-]{20,}$#', trim($urlOrId))) {
            return trim($urlOrId);
        }

        return null;
    }
}
