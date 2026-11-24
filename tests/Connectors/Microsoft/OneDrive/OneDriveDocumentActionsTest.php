<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Microsoft\OneDrive\Actions\CreateOneDriveDocumentAction;
use Kanvas\Connectors\Microsoft\OneDrive\Actions\EditExcelDocumentAction;
use Kanvas\Connectors\Microsoft\OneDrive\Actions\EditWordDocumentAction;
use Kanvas\Connectors\Microsoft\OneDrive\Actions\ReadOneDriveDocumentAction;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneDriveDocumentActionsTest extends TestCase
{
    private function client(): OneDriveClient
    {
        return new OneDriveClient('token');
    }

    private function meta(string $name, string $etag = '"e1"'): array
    {
        return ['id' => 'I1', 'name' => $name, 'size' => 100, 'eTag' => $etag];
    }

    private function fakeFile(string $name, string $content): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => Http::response($content, 200),
            '*' => Http::response($this->meta($name), 200),
        ]);
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

    public function test_read_word_returns_text(): void
    {
        $this->fakeFile('a.docx', WordDocument::create(['Hola mundo']));

        $result = new ReadOneDriveDocumentAction($this->client(), '/a.docx')->execute();

        $this->assertSame('word', $result['type']);
        $this->assertStringContainsString('Hola mundo', $result['text']);
        $this->assertFalse($result['truncated']);
    }

    public function test_read_excel_returns_rows(): void
    {
        $this->fakeFile('a.xlsx', ExcelDocument::create([['Mes', 'Total'], ['Enero', 100]]));

        $result = new ReadOneDriveDocumentAction($this->client(), '/a.xlsx')->execute();

        $this->assertSame('excel', $result['type']);
        $this->assertSame('Enero', $result['rows'][1][0]);
    }

    public function test_read_text_file_returns_content(): void
    {
        $this->fakeFile('n.txt', str_repeat('é', 20005));

        $result = new ReadOneDriveDocumentAction($this->client(), '/n.txt')->execute();

        $this->assertSame('text', $result['type']);
        $this->assertSame(20000, mb_strlen($result['text']));
        $this->assertTrue($result['truncated']);
    }

    public function test_edit_word_downloads_edits_and_replaces_with_etag(): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response($this->meta('a.docx', '"e2"'), 200)
                : Http::response(WordDocument::create(['Hola mundo']), 200),
            '*' => Http::response($this->meta('a.docx'), 200),
        ]);

        $result = new EditWordDocumentAction(
            $this->client(),
            '/a.docx',
            ['mundo' => 'Kanvas'],
            []
        )->execute();

        $this->assertSame('"e2"', $result['item']['etag']);
        $this->assertSame(['mundo' => 1], $result['replaced']);
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return $request->header('If-Match') === ['"e1"']
                && str_contains(WordDocument::readText($request->body()), 'Hola Kanvas');
        });
    }

    public function test_edit_excel_replaces_with_new_cells(): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response($this->meta('a.xlsx', '"e2"'), 200)
                : Http::response(ExcelDocument::create([['Mes', 'Total']]), 200),
            '*' => Http::response($this->meta('a.xlsx'), 200),
        ]);

        new EditExcelDocumentAction(
            $this->client(),
            '/a.xlsx',
            sheet: null,
            cells: ['B1' => 'Suma'],
            appendRows: []
        )->execute();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && $request->header('If-Match') === ['"e1"']
                && ExcelDocument::read($request->body())['rows'][0][1] === 'Suma';
        });
    }

    public static function unsupportedFileNames(): array
    {
        return [
            'pdf' => ['informe.pdf'],
            'doc' => ['contrato.doc'],
            'xls' => ['ventas.xls'],
        ];
    }

    #[DataProvider('unsupportedFileNames')]
    public function test_editing_unsupported_files_does_not_download(string $name): void
    {
        Http::fake(['*' => Http::response($this->meta($name), 200)]);

        $this->assertReason(
            OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
            fn () => new EditWordDocumentAction(
                $this->client(),
                '/' . $name,
                ['a' => 'b'],
                []
            )->execute()
        );
        $this->assertReason(
            OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
            fn () => new EditExcelDocumentAction(
                $this->client(),
                '/' . $name,
                sheet: null,
                cells: ['A1' => 1],
                appendRows: []
            )->execute()
        );
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/content'));
    }

    public function test_each_edit_action_names_the_one_type_it_edits(): void
    {
        Http::fake(['*' => Http::response($this->meta('ventas.xlsx'), 200)]);

        try {
            new EditWordDocumentAction(
                $this->client(),
                '/ventas.xlsx',
                ['a' => 'b'],
                []
            )->execute();
            $this->fail('OneDriveException was not thrown');
        } catch (OneDriveException $e) {
            $this->assertSame('Only .docx files can be edited with this tool.', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_edit_refuses_to_overwrite_without_a_version_tag(): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => Http::response(WordDocument::create(['Hola mundo']), 200),
            '*' => Http::response(['id' => 'I1', 'name' => 'a.docx', 'size' => 100], 200),
        ]);

        $this->assertReason(
            OneDriveErrorReasonEnum::REQUEST_FAILED,
            fn () => new EditWordDocumentAction(
                $this->client(),
                '/a.docx',
                ['mundo' => 'Kanvas'],
                []
            )->execute()
        );
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_edit_without_changes_makes_no_http_call(): void
    {
        Http::fake();

        $this->assertReason(
            OneDriveErrorReasonEnum::REQUEST_FAILED,
            fn () => new EditWordDocumentAction(
                $this->client(),
                '/a.docx',
                [],
                []
            )->execute()
        );
        $this->assertReason(
            OneDriveErrorReasonEnum::REQUEST_FAILED,
            fn () => new EditExcelDocumentAction(
                $this->client(),
                '/a.xlsx',
                sheet: null,
                cells: [],
                appendRows: []
            )->execute()
        );
        Http::assertNothingSent();
    }

    public function test_create_adds_the_missing_extension(): void
    {
        Http::fake(['*' => Http::response($this->meta('Ventas.xlsx'), 201)]);

        new CreateOneDriveDocumentAction(
            $this->client(),
            OneDriveDocumentTypeEnum::EXCEL,
            'Ventas',
            folder: null,
            content: [['Mes', 'Total']]
        )->execute();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://graph.microsoft.com/v1.0/me/drive/root:/Ventas.xlsx:/content?@microsoft.graph.conflictBehavior=fail');
    }
}
