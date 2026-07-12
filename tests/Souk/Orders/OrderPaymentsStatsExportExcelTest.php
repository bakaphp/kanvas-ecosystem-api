<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Support\Collection;
use Kanvas\Souk\Orders\Exports\OrderPaymentsStatsExportExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ReflectionClass;
use Tests\TestCase;

class OrderPaymentsStatsExportExcelTest extends TestCase
{
    private function makeExport(?array $metadata = null, bool $includeSummary = true, string $language = 'en'): OrderPaymentsStatsExportExcel
    {
        $stats = $includeSummary ? [
            'totals'   => ['total_transactions' => 0, 'total_amount' => 0],
            'byPeriod' => [],
            'periods'  => [],
        ] : [];

        return new OrderPaymentsStatsExportExcel(
            stats: $stats,
            orders: new Collection(),
            language: $language,
            metadata: $metadata,
            includeSummary: $includeSummary,
        );
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref  = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }

    private function invoke(object $object, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($object);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($object, $args);
    }

    public function testDefaultHeaderColorIsGreen(): void
    {
        $export = $this->makeExport();

        $this->assertEquals('5D8A66', $this->readProperty($export, 'headerBg'));
    }

    public function testCustomHeaderColorIsNormalized(): void
    {
        $export = $this->makeExport(['headerColor' => '#123abc']);

        $this->assertEquals('123ABC', $this->readProperty($export, 'headerBg'));
    }

    public function testInvalidHeaderColorFallsBackToGreen(): void
    {
        foreach (['nope', '12345', 'ZZZZZZ', ''] as $bad) {
            $export = $this->makeExport(['headerColor' => $bad]);
            $this->assertEquals('5D8A66', $this->readProperty($export, 'headerBg'), "value: {$bad}");
        }
    }

    public function testHeaderSectionRendersTitleAndSubtitle(): void
    {
        $export = $this->makeExport([
            'custom_title' => 'Reporte de Pagos',
            'subtitle'     => 'Periodo julio',
        ]);

        $sheet = new Spreadsheet()->getActiveSheet();
        $next  = $this->invoke($export, 'writeHeaderSection', [$sheet, 1]);

        $this->assertEquals('REPORTE DE PAGOS', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Periodo julio', $sheet->getCell('A2')->getValue());
        $this->assertGreaterThan(2, $next);
    }

    public function testHeaderSectionSkippedWithoutMetadata(): void
    {
        $export = $this->makeExport();

        $sheet = new Spreadsheet()->getActiveSheet();
        $next  = $this->invoke($export, 'writeHeaderSection', [$sheet, 1]);

        $this->assertEquals(1, $next);
        $this->assertNull($sheet->getCell('A1')->getValue());
    }

    public function testSummaryBlockRenderedByDefault(): void
    {
        $export = $this->makeExport(includeSummary: true, language: 'es');

        $sheet = new Spreadsheet()->getActiveSheet();
        $this->invoke($export, 'build', [$sheet]);

        $this->assertStringContainsStringInSheet($sheet, 'Resumen por Período');
        $this->assertStringContainsStringInSheet($sheet, 'Detalle de Órdenes');
    }

    public function testSummaryBlockHiddenWhenDisabled(): void
    {
        $export = $this->makeExport(includeSummary: false, language: 'es');

        $sheet = new Spreadsheet()->getActiveSheet();
        $this->invoke($export, 'build', [$sheet]);

        $this->assertStringNotContainsStringInSheet($sheet, 'Resumen por Período');
        $this->assertStringNotContainsStringInSheet($sheet, 'Métrica');
        $this->assertStringContainsStringInSheet($sheet, 'Detalle de Órdenes');
    }

    private function sheetText(Worksheet $sheet): string
    {
        $text = '';
        foreach ($sheet->toArray() as $row) {
            $text .= implode('|', array_map(fn ($c) => (string) $c, $row)) . "\n";
        }

        return $text;
    }

    private function assertStringContainsStringInSheet(Worksheet $sheet, string $needle): void
    {
        $this->assertStringContainsString($needle, $this->sheetText($sheet));
    }

    private function assertStringNotContainsStringInSheet(Worksheet $sheet, string $needle): void
    {
        $this->assertStringNotContainsString($needle, $this->sheetText($sheet));
    }

    public function testHeaderStyleUsesConfiguredColor(): void
    {
        $export = $this->makeExport(['headerColor' => '#AABBCC']);

        /** @var Worksheet $sheet */
        $sheet = new Spreadsheet()->getActiveSheet();
        $sheet->setCellValue('A1', 'x');
        $this->invoke($export, 'applyHeaderStyle', [$sheet, 'A1:C1']);

        $this->assertEquals('AABBCC', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
    }
}
