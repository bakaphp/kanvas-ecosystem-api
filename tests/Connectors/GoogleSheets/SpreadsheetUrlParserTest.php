<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Support\SpreadsheetUrlParser;
use Tests\TestCase;

class SpreadsheetUrlParserTest extends TestCase
{
    public function test_extracts_the_id_from_a_full_url_with_a_tab_fragment(): void
    {
        $id = SpreadsheetUrlParser::extractId(
            'https://docs.google.com/spreadsheets/d/1A_B_C_D_12345/edit#gid=0'
        );

        $this->assertSame('1A_B_C_D_12345', $id);
    }

    public function test_extracts_the_id_from_a_url_with_no_trailing_path(): void
    {
        $id = SpreadsheetUrlParser::extractId('https://docs.google.com/spreadsheets/d/1A_B_C_D_12345');

        $this->assertSame('1A_B_C_D_12345', $id);
    }

    public function test_accepts_a_bare_spreadsheet_id(): void
    {
        $id = SpreadsheetUrlParser::extractId('1A_B_C_D_12345678901234567890');

        $this->assertSame('1A_B_C_D_12345678901234567890', $id);
    }

    public function test_returns_null_for_an_unrelated_url(): void
    {
        $this->assertNull(SpreadsheetUrlParser::extractId('https://example.com/not-a-sheet'));
    }

    public function test_returns_null_for_a_too_short_bare_string(): void
    {
        $this->assertNull(SpreadsheetUrlParser::extractId('short'));
    }
}
