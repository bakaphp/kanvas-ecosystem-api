<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Tests\TestCase;

final class CsvExportServiceTest extends TestCase
{
    public function test_builds_excel_friendly_csv_with_bom_crlf_and_quoted_cells(): void
    {
        $csv = new CsvExportService()->buildCsv(
            ['CRM', 'Persona', 'Email'],
            [
                ['Intras', 'Ana "La Jefa" Rodríguez', 'ana@x.com'],
                ['Skills', 'Roberto Santana', 'r.santana@bpd.com.do'],
            ],
        );

        // UTF-8 BOM prefix
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // CRLF line endings, every cell quoted, embedded quote doubled
        $expectedBody = "\"CRM\",\"Persona\",\"Email\"\r\n"
            . "\"Intras\",\"Ana \"\"La Jefa\"\" Rodríguez\",\"ana@x.com\"\r\n"
            . "\"Skills\",\"Roberto Santana\",\"r.santana@bpd.com.do\"\r\n";

        $this->assertSame("\xEF\xBB\xBF" . $expectedBody, $csv);
    }
}
