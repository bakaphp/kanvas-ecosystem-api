<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\WordDocument;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\CreateOneDriveDocumentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\CreateOneDriveFolderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\DeleteOneDriveItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\EditOneDriveExcelTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\EditOneDriveWordTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\ListOneDriveFolderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\ReadOneDriveDocumentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\SearchOneDriveTool;
use NeuronAI\Tools\TrackByInputs;
use Override;
use ReflectionProperty;
use Tests\TestCase;
use ZipArchive;

class OneDriveToolsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_search_returns_items(): void
    {
        Http::fake([
            '*' => Http::response(['value' => [
                ['id' => 'I1', 'name' => 'contrato.docx', 'size' => 10, 'file' => ['mimeType' => 'x']],
            ]], 200),
        ]);

        $result = $this->tool(new SearchOneDriveTool())->__invoke(query: 'contrato');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('contrato.docx', $result['items'][0]['name']);
    }

    public function test_search_limits_results_to_the_given_folder(): void
    {
        Http::fake([
            '*' => Http::response(['value' => [
                ['id' => 'I1', 'name' => 'contrato.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Legal']],
                ['id' => 'I2', 'name' => 'contrato-viejo.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Archivo']],
            ]], 200),
        ]);

        $result = $this->tool(new SearchOneDriveTool())->__invoke(query: 'contrato', folder: '/Legal');

        $this->assertTrue($result['success']);
        $this->assertSame(['contrato.docx'], array_column($result['items'], 'name'));
    }

    public function test_per_item_tools_budget_runs_per_inputs_and_delete_keeps_one_ceiling(): void
    {
        $perItem = [
            new ReadOneDriveDocumentTool(),
            new ListOneDriveFolderTool(),
            new EditOneDriveWordTool(),
            new EditOneDriveExcelTool(),
            new CreateOneDriveDocumentTool(),
            new CreateOneDriveFolderTool(),
        ];

        foreach ($perItem as $tool) {
            $this->assertContains(TrackByInputs::class, class_uses_recursive($tool), $tool::class);
        }

        $delete = new DeleteOneDriveItemTool();
        $this->assertNotContains(TrackByInputs::class, class_uses_recursive($delete));
        $this->assertSame('onedrive_delete_item', $delete->getRunKey());
    }

    public function test_a_confined_drive_tells_the_model_what_it_cannot_see(): void
    {
        [$app, $company] = $this->context();
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 404)]);

        $confined = new ListOneDriveFolderTool()
            ->withContext($app, $company, static::$cachedUser)
            ->withOneDriveClient(new OneDriveClient('token', rootFolder: '/Clientes'))
            ->__invoke(path_or_id: '/Documents');
        $whole = $this->tool(new ListOneDriveFolderTool())->__invoke(path_or_id: '/Documents');

        $this->assertSame('not_found', $confined['reason']);
        $this->assertStringContainsString('limited to one folder', $confined['scope']);
        $this->assertArrayNotHasKey('scope', $whole);
    }

    public function test_edit_word_rejects_invalid_json_without_calling_graph(): void
    {
        Http::fake();

        $result = $this->tool(new EditOneDriveWordTool())->__invoke(
            path_or_id: '/a.docx',
            replacements: '{bad',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_input', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_edit_excel_rejects_wrong_json_shape_without_calling_graph(): void
    {
        Http::fake();

        $result = $this->tool(new EditOneDriveExcelTool())->__invoke(
            path_or_id: '/a.xlsx',
            append_rows: '["not", "rows"]',
        );

        $this->assertSame('invalid_input', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_edit_excel_applies_cells(): void
    {
        $this->fakeFile('a.xlsx', ExcelDocument::create([['Mes', 'Total']]));

        $result = $this->tool(new EditOneDriveExcelTool())->__invoke(
            path_or_id: '/a.xlsx',
            cells: '{"B1": "Suma"}',
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && ExcelDocument::read($request->body())['rows'][0][1] === 'Suma');
    }

    public function test_create_document_rejects_unknown_type(): void
    {
        Http::fake();

        $result = $this->tool(new CreateOneDriveDocumentTool())->__invoke(type: 'pdf', name: 'x');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_input', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_delete_moves_to_recycle_bin(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response('', 204)
            : Http::response(['id' => 'I1', 'name' => 'a.docx', 'file' => []], 200));

        $result = $this->tool(new DeleteOneDriveItemTool())->__invoke(path_or_id: 'I1');

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/me/drive/items/I1'));
    }

    public function test_delete_refuses_the_drive_root_without_calling_graph(): void
    {
        Http::fake();

        foreach (['', '/', '//', '  ', "\r", "/\r/"] as $target) {
            $result = $this->tool(new DeleteOneDriveItemTool())->__invoke(path_or_id: $target);

            $this->assertFalse($result['success']);
            $this->assertSame('invalid_input', $result['reason']);
        }

        Http::assertNothingSent();
    }

    public function test_an_invalid_argument_from_below_is_a_request_failure_not_invalid_input(): void
    {
        $client = new class ('token') extends OneDriveClient {
            #[Override]
            public function delete(string $pathOrId): void
            {
                throw new InvalidArgumentException('broken internals');
            }
        };
        [$app, $company] = $this->context();

        $result = new DeleteOneDriveItemTool()
            ->withContext($app, $company, static::$cachedUser)
            ->withOneDriveClient($client)
            ->__invoke(path_or_id: 'I1');

        $this->assertFalse($result['success']);
        $this->assertSame('request_failed', $result['reason']);
    }

    public function test_a_missing_upload_url_is_reported_as_request_failed(): void
    {
        $content = $this->wordDocumentOver4Mb();
        Http::fake([
            '*/createUploadSession' => Http::response([], 200),
            '*/me/drive/items/I1/content' => Http::response($content, 200),
            '*' => Http::response(['id' => 'I1', 'name' => 'a.docx', 'size' => strlen($content), 'eTag' => '"e1"'], 200),
        ]);

        $result = $this->tool(new EditOneDriveWordTool())->__invoke(
            path_or_id: '/a.docx',
            replacements: '{"Hola": "Adios"}',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('request_failed', $result['reason']);
        $this->assertSame('OneDrive did not return an upload URL', $result['message']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/drive/items/I1/createUploadSession'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_file_changed_is_returned_to_the_agent(): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['error' => ['message' => 'changed']], 412)
                : Http::response(WordDocument::create(['Hola mundo']), 200),
            '*' => Http::response(['id' => 'I1', 'name' => 'a.docx', 'size' => 100, 'eTag' => '"e1"'], 200),
        ]);

        $result = $this->tool(new EditOneDriveWordTool())->__invoke(
            path_or_id: '/a.docx',
            replacements: '{"Hola": "Adios"}',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('file_changed', $result['reason']);
    }

    public function test_every_tool_has_a_literal_description_and_agent_tool_attribute(): void
    {
        $classes = [
            SearchOneDriveTool::class,
            ListOneDriveFolderTool::class,
            ReadOneDriveDocumentTool::class,
            EditOneDriveWordTool::class,
            EditOneDriveExcelTool::class,
            CreateOneDriveDocumentTool::class,
            CreateOneDriveFolderTool::class,
            DeleteOneDriveItemTool::class,
        ];

        foreach ($classes as $class) {
            $this->assertNotNull(AgentTool::fromClass($class), $class);
            $description = new ReflectionProperty($class, 'description')->getDefaultValue();
            $this->assertIsString($description, $class);
            $this->assertNotSame('', $description, $class);
        }
    }

    private function fakeFile(string $name, string $content): void
    {
        Http::fake([
            '*/me/drive/items/I1/content' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['id' => 'I1', 'name' => $name, 'size' => 1, 'eTag' => '"e2"'], 200)
                : Http::response($content, 200),
            '*' => Http::response(['id' => 'I1', 'name' => $name, 'size' => 100, 'eTag' => '"e1"'], 200),
        ]);
    }

    /**
     * Random bytes do not compress, so the stored media entry keeps the archive above the simple-upload limit.
     */
    private function wordDocumentOver4Mb(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-docx-test');

        try {
            file_put_contents($path, WordDocument::create(['Hola mundo']));
            $zip = new ZipArchive();
            $zip->open($path);
            $zip->addFromString('word/media/padding.bin', random_bytes(OneDriveClient::SIMPLE_UPLOAD_MAX_BYTES + 1024));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function tool(object $tool): object
    {
        [$app, $company] = $this->context();

        return $tool
            ->withContext($app, $company, static::$cachedUser)
            ->withOneDriveClient(new OneDriveClient('token'));
    }

    private function context(): array
    {
        return [app(Apps::class), static::$cachedUser->getCurrentCompany()];
    }
}
