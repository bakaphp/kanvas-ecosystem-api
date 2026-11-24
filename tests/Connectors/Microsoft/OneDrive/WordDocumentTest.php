<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use DOMDocument;
use DOMXPath;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class WordDocumentTest extends TestCase
{
    private function fixture(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 20]);
        $section = $phpWord->addSection();
        $section->addTitle('Informe anual', 1);
        $section->addText('Fecha: 2025');

        $run = $section->addTextRun();
        $run->addText('Pago a ');
        $run->addText('Acme', ['bold' => true]);
        $run->addText(' en enero');

        $table = $section->addTable();
        $table->addRow();
        $table->addCell(2000)->addText('Celda uno');
        $table->addCell(2000)->addText('Celda dos');

        return $this->write($phpWord);
    }

    private function write(PhpWord $phpWord): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-docx-test');

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function entry(string $bytes, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-docx-test');

        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $content = $zip->getFromName($name);
            $zip->close();
            $this->assertIsString($content);

            return $content;
        } finally {
            @unlink($path);
        }
    }

    private function assertReason(OneDriveErrorReasonEnum $expected, callable $call): void
    {
        try {
            $call();
        } catch (OneDriveException $e) {
            $this->assertSame($expected, $e->reason);

            return;
        }

        $this->fail('OneDriveException was not thrown');
    }

    public function test_read_text_returns_paragraphs_and_table_cells(): void
    {
        $lines = explode("\n", WordDocument::readText($this->fixture()));

        $this->assertContains('Informe anual', $lines);
        $this->assertContains('Fecha: 2025', $lines);
        $this->assertContains('Pago a Acme en enero', $lines);
        $this->assertContains('Celda uno', $lines);
        $this->assertContains('Celda dos', $lines);
    }

    public function test_replace_inside_a_single_run(): void
    {
        $result = WordDocument::replaceText($this->fixture(), ['Fecha: 2025' => 'Fecha: 2026']);

        $this->assertSame(['Fecha: 2025' => 1], $result['replaced']);
        $lines = explode("\n", WordDocument::readText($result['bytes']));
        $this->assertContains('Fecha: 2026', $lines);
        $this->assertNotContains('Fecha: 2025', $lines);
    }

    public function test_replace_across_runs_keeps_the_document_valid(): void
    {
        $result = WordDocument::replaceText($this->fixture(), ['a Acme en' => 'a Globex en']);

        $this->assertSame(['a Acme en' => 1], $result['replaced']);
        $this->assertStringContainsString('Pago a Globex en enero', WordDocument::readText($result['bytes']));

        $xml = new DOMDocument();
        $this->assertTrue($xml->loadXML($this->entry($result['bytes'], 'word/document.xml')));
    }

    public function test_replace_keeps_untouched_styles(): void
    {
        $original = $this->fixture();
        $result = WordDocument::replaceText($original, ['Fecha: 2025' => 'Fecha: 2026']);

        $this->assertSame(
            $this->entry($original, 'word/styles.xml'),
            $this->entry($result['bytes'], 'word/styles.xml')
        );
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"/>', $this->entry($result['bytes'], 'word/document.xml'));
    }

    public function test_missing_text_throws_text_not_found(): void
    {
        $this->assertReason(
            OneDriveErrorReasonEnum::TEXT_NOT_FOUND,
            fn () => WordDocument::replaceText($this->fixture(), [
                'Fecha: 2025' => 'Fecha: 2026',
                'No existe' => 'Nada',
            ])
        );
    }

    public function test_append_paragraphs_adds_them_at_the_end(): void
    {
        $bytes = WordDocument::appendParagraphs($this->fixture(), ['Nota final', 'Tom & Jerry <ok>']);

        $lines = explode("\n", WordDocument::readText($bytes));
        $this->assertSame(['Nota final', 'Tom & Jerry <ok>'], array_slice($lines, -2));

        $xml = new DOMDocument();
        $this->assertTrue($xml->loadXML($this->entry($bytes, 'word/document.xml')));
        $body = $xml->getElementsByTagNameNS(WordDocument::WORD_NAMESPACE, 'body')->item(0);
        $this->assertSame('sectPr', $body->lastChild->localName);
    }

    public function test_create_builds_a_document_with_the_given_paragraphs(): void
    {
        $bytes = WordDocument::create(['Primero', 'Segundo & tercero']);

        $this->assertSame("Primero\nSegundo & tercero", WordDocument::readText($bytes));
    }

    private function tabbedFixture(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Total:');
        $section->addText('Uno dos tres');

        $original = $this->write($phpWord);
        $xml = str_replace(
            '<w:t xml:space="preserve">Total:</w:t></w:r>',
            '<w:t xml:space="preserve">Total:</w:t><w:tab/><w:t>100</w:t><w:br/><w:t>fin</w:t></w:r>',
            $this->entry($original, 'word/document.xml')
        );
        $this->assertStringContainsString('<w:tab/><w:t>100</w:t>', $xml);

        return $this->withEntry($original, 'word/document.xml', $xml);
    }

    private function withEntry(string $bytes, string $name, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-docx-test');

        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $zip->addFromString($name, $content);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function assertValidDocumentXml(string $bytes): DOMDocument
    {
        $xml = new DOMDocument();
        $this->assertTrue($xml->loadXML($this->entry($bytes, 'word/document.xml')));

        return $xml;
    }

    public function test_append_strips_xml_invalid_characters(): void
    {
        $bytes = WordDocument::appendParagraphs($this->fixture(), ["bad\x0Bchar", "inv\xC3alid\x1B"]);

        $this->assertValidDocumentXml($bytes);
        $lines = explode("\n", WordDocument::readText($bytes));
        $this->assertSame('badchar', $lines[count($lines) - 2]);
        $this->assertTrue(mb_check_encoding(end($lines), 'UTF-8'));
        $this->assertStringStartsWith('inv', end($lines));
    }

    public function test_replace_strips_xml_invalid_characters(): void
    {
        $result = WordDocument::replaceText($this->tabbedFixture(), ['Total:' => "x\x0By"]);

        $this->assertValidDocumentXml($result['bytes']);
        $this->assertStringStartsWith("xy\t100\nfin", WordDocument::readText($result['bytes']));
    }

    public function test_read_text_renders_tabs_and_breaks(): void
    {
        $this->assertStringStartsWith("Total:\t100\nfin\nUno dos tres", WordDocument::readText($this->tabbedFixture()));
    }

    public function test_replace_does_not_match_across_a_tab(): void
    {
        $this->assertReason(
            OneDriveErrorReasonEnum::TEXT_NOT_FOUND,
            fn () => WordDocument::replaceText($this->tabbedFixture(), ['Total:100' => 'X'])
        );
    }

    public function test_replace_refuses_a_search_that_spans_a_tab(): void
    {
        $this->assertReason(
            OneDriveErrorReasonEnum::TEXT_NOT_FOUND,
            fn () => WordDocument::replaceText($this->tabbedFixture(), ["Total:\t100" => 'X'])
        );
    }

    public function test_replace_keeps_the_tab_in_place(): void
    {
        $result = WordDocument::replaceText($this->tabbedFixture(), ['Total:' => 'Sum:']);

        $this->assertSame(['Total:' => 1], $result['replaced']);
        $this->assertStringContainsString(
            '<w:t xml:space="preserve">Sum:</w:t><w:tab/><w:t>100</w:t>',
            $this->entry($result['bytes'], 'word/document.xml')
        );
    }

    public function test_a_key_left_without_matches_by_an_earlier_replacement_throws(): void
    {
        $this->assertReason(
            OneDriveErrorReasonEnum::TEXT_NOT_FOUND,
            fn () => WordDocument::replaceText($this->fixture(), [
                'Fecha:' => 'Dia:',
                'Fecha: 2025' => 'X',
            ])
        );
    }

    public function test_replace_across_three_runs_empties_the_middle_run(): void
    {
        $result = WordDocument::replaceText($this->fixture(), ['o a Acme en e' => 'o a Globex en e']);

        $this->assertSame(['o a Acme en e' => 1], $result['replaced']);
        $this->assertContains('Pago a Globex en enero', explode("\n", WordDocument::readText($result['bytes'])));

        $xml = $this->assertValidDocumentXml($result['bytes']);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('w', WordDocument::WORD_NAMESPACE);
        $texts = [];
        foreach ($xpath->query('//w:p[w:r/w:rPr/w:b]/w:r/w:t') as $node) {
            $texts[] = $node->textContent;
        }
        $this->assertSame(['Pago a Globex en e', '', 'nero'], $texts);
    }

    public function test_replace_counts_every_occurrence(): void
    {
        $result = WordDocument::replaceText($this->fixture(), ['Celda' => 'Cell']);

        $this->assertSame(['Celda' => 2], $result['replaced']);
        $lines = explode("\n", WordDocument::readText($result['bytes']));
        $this->assertContains('Cell uno', $lines);
        $this->assertContains('Cell dos', $lines);
    }

    public function test_non_docx_bytes_are_unsupported(): void
    {
        $this->assertReason(
            OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
            fn () => WordDocument::readText('not a zip file')
        );
    }
}
