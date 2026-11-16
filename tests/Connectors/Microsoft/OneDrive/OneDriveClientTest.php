<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Connectors\Microsoft\OneDrive\Support\DriveItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneDriveClientTest extends TestCase
{
    use DatabaseTransactions;

    private const string GRAPH = 'https://graph.microsoft.com/v1.0';

    private function client(): OneDriveClient
    {
        return new OneDriveClient('token');
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

    public function test_search_returns_normalized_items(): void
    {
        Http::fake([
            '*' => Http::response([
                'value' => [
                    [
                        'id' => 'F1',
                        'name' => 'a.docx',
                        'size' => 10,
                        'eTag' => '"e1"',
                        'webUrl' => 'https://x/a',
                        'lastModifiedDateTime' => '2026-01-01T00:00:00Z',
                        'file' => [],
                        'parentReference' => ['path' => '/drive/root:/Docs'],
                    ],
                    [
                        'id' => 'D1',
                        'name' => 'Docs',
                        'folder' => ['childCount' => 1],
                        'parentReference' => ['path' => '/drive/root:'],
                    ],
                ],
            ]),
        ]);

        $items = $this->client()->search("it's", 5);

        $this->assertSame('file', $items[0]['type']);
        $this->assertSame('/Docs/a.docx', $items[0]['path']);
        $this->assertSame('"e1"', $items[0]['etag']);
        $this->assertSame('folder', $items[1]['type']);
        $this->assertSame('/Docs', $items[1]['path']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer token')
            && str_contains($r->url(), self::GRAPH . "/me/drive/root/search(q='it%27%27s')?")
            && str_contains($r->url(), 'top=5'));
    }

    public function test_search_in_a_folder_keeps_only_items_under_it_across_pages(): void
    {
        $item = fn (string $name, string $parent) => ['id' => $name, 'name' => $name, 'file' => [], 'parentReference' => ['path' => $parent]];

        Http::fake([
            self::GRAPH . '/next-page' => Http::response(['value' => [
                $item('b.docx', '/drive/root:/docs/Sub'),
                $item('c.docx', '/drive/root:/Other'),
            ]]),
            '*' => Http::response([
                'value' => [
                    $item('a.docx', '/drive/root:/Docs'),
                    $item('x.docx', '/drive/root:/Docsy'),
                    $item('Docs', '/drive/root:'),
                ],
                '@odata.nextLink' => self::GRAPH . '/next-page',
            ]),
        ]);

        $items = $this->client()->search('informe', folder: '/Docs/');

        $this->assertSame(['a.docx', 'b.docx'], array_column($items, 'name'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/drive/root/search(') && str_contains($r->url(), 'top=200'));
    }

    public function test_search_never_follows_a_next_link_outside_graph(): void
    {
        Http::fake(['*' => Http::response([
            'value' => [['id' => 'a', 'name' => 'a.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Docs']]],
            '@odata.nextLink' => 'http://169.254.169.254/latest/meta-data',
        ])]);

        $items = $this->client()->search('informe', folder: '/Docs');

        $this->assertCount(1, $items);
        Http::assertSentCount(1);
    }

    public function test_paths_drop_the_drive_qualified_root_prefix(): void
    {
        $item = fn (string $parent) => DriveItem::fromGraph(['name' => 'Ventas.xlsx', 'parentReference' => ['path' => $parent]]);

        $this->assertSame('/Ventas.xlsx', $item('/drives/0F2813AE680A7D81/root:')['path']);
        $this->assertSame('/Docs/Ventas.xlsx', $item('/drives/b!AbC-12_x/root:/Docs')['path']);
        $this->assertSame('/Docs/Ventas.xlsx', $item('/drive/root:/Docs')['path']);
    }

    public function test_paths_are_encoded_per_segment(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'name' => 'x', 'file' => []])]);

        $this->client()->getItem('/Documentos/Contrato Año #2.docx');

        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH . '/me/drive/root:/Documentos/Contrato%20A%C3%B1o%20%232.docx:');
    }

    public function test_ids_address_items_directly(): void
    {
        Http::fake(['*' => Http::response(['id' => '01ABC', 'name' => 'x', 'file' => []])]);

        $this->client()->getItem('01ABC');

        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH . '/me/drive/items/01ABC');
    }

    public function test_download_refuses_files_over_20_mb_before_fetching_content(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'name' => 'big', 'size' => 20971521, 'file' => []])]);

        $this->assertReason(OneDriveErrorReasonEnum::TOO_LARGE, fn () => $this->client()->download($this->client()->getItem('1')));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/content'));
    }

    public function test_download_returns_content(): void
    {
        Http::fake([
            '*/content' => Http::response('hello'),
            '*' => Http::response(['id' => '1', 'name' => 'a.txt', 'size' => 5, 'file' => []]),
        ]);

        $result = $this->client()->download($this->client()->getItem('1'));

        $this->assertSame('hello', $result['content']);
        $this->assertSame('a.txt', $result['item']['name']);
    }

    public function test_replace_sends_if_match_and_maps_412_to_file_changed(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'etag']], 412)]);

        $this->assertReason(OneDriveErrorReasonEnum::FILE_CHANGED, fn () => $this->client()->replace('1', 'abc', '"e1"'));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('If-Match', '"e1"'));
    }

    public function test_replace_uses_an_upload_session_above_4_mb(): void
    {
        $upload = 'https://upload.example.com/session/1';
        Http::fake([
            '*createUploadSession' => Http::response(['uploadUrl' => $upload]),
            'upload.example.com/*' => Http::response(['id' => '1', 'name' => 'a', 'file' => []]),
        ]);

        $size = 4 * 1024 * 1024 + 1;
        $item = $this->client()->replace('1', str_repeat('a', $size), '"e1"');

        $this->assertSame('1', $item['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/me/drive/items/1/createUploadSession')
            && $r->hasHeader('If-Match', '"e1"')
            && $r['item']['@microsoft.graph.conflictBehavior'] === 'replace');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === $upload
            && $r->hasHeader('Content-Range', 'bytes 0-4194304/4194305')
            && ! $r->hasHeader('Authorization'));
    }

    public function test_upload_session_sends_5_mib_chunks(): void
    {
        $upload = 'https://upload.example.com/session/1';
        Http::fake([
            '*createUploadSession' => Http::response(['uploadUrl' => $upload]),
            'upload.example.com/*' => Http::response(['id' => '1', 'name' => 'a', 'file' => []]),
        ]);

        $this->client()->replace('1', str_repeat('a', 2 * 5242880 + 10), '"e1"');

        $ranges = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->url() === $upload) {
                $ranges[] = $request->header('Content-Range')[0];
            }
        }

        $this->assertSame([
            'bytes 0-5242879/10485770',
            'bytes 5242880-10485759/10485770',
            'bytes 10485760-10485769/10485770',
        ], $ranges);
    }

    public function test_upload_session_without_an_upload_url_fails_without_uploading(): void
    {
        Http::fake(['*createUploadSession' => Http::response([])]);

        try {
            $this->client()->createFile('/Docs', 'big.bin', str_repeat('a', 4 * 1024 * 1024 + 1));
            $this->fail('OneDriveException was not thrown');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertSame('OneDrive did not return an upload URL', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_create_file_never_overwrites(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'exists']], 409)]);

        $this->assertReason(OneDriveErrorReasonEnum::NAME_CONFLICT, fn () => $this->client()->createFile('/Docs', 'a b.txt', 'x'));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'conflictBehavior=fail')
            && str_contains($r->url(), '/me/drive/root:/Docs/a%20b.txt:/content'));
    }

    public function test_create_file_in_root_and_create_folder(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'name' => 'n', 'file' => []])]);

        $this->client()->createFile(null, 'a.txt', 'x');
        $this->client()->createFolder('01ABC', 'New');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/drive/root:/a.txt:/content'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/me/drive/items/01ABC/children')
            && $r['@microsoft.graph.conflictBehavior'] === 'fail');
    }

    public function test_forbidden_maps_to_permission_missing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'denied']], 403)]);

        $this->assertReason(OneDriveErrorReasonEnum::PERMISSION_MISSING, fn () => $this->client()->listFolder(null));
    }

    public function test_locked_maps_to_file_locked(): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'code' => 'resourceLocked',
            'message' => 'The resource you are attempting to access is locked',
        ]], 423)]);

        $this->assertReason(OneDriveErrorReasonEnum::FILE_LOCKED, fn () => $this->client()->listFolder(null));
    }

    public static function accountsWithoutADrive(): array
    {
        return [
            'work tenant without SharePoint' => [400, 'Tenant does not have a SPO license.'],
            'work account never provisioned' => [404, "User's mysite not found."],
        ];
    }

    #[DataProvider('accountsWithoutADrive')]
    public function test_account_without_a_drive_maps_to_onedrive_not_available(int $status, string $message): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'x', 'message' => $message]], $status)]);

        $this->assertReason(OneDriveErrorReasonEnum::ONEDRIVE_NOT_AVAILABLE, fn () => $this->client()->listFolder(null));
    }

    public function test_delete_refuses_the_drive_root(): void
    {
        Http::fake();

        foreach (['', '/', '//', "\r", "/\r/"] as $target) {
            try {
                $this->client()->delete($target);
                $this->fail('OneDriveException was not thrown');
            } catch (OneDriveException $e) {
                $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
                $this->assertSame('Refusing to delete the OneDrive root folder', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_delete_refuses_an_item_graph_reports_as_the_root(): void
    {
        Http::fake(['*' => Http::response(['id' => 'ROOTID', 'root' => (object) []])]);

        try {
            $this->client()->delete('root');
            $this->fail('OneDriveException was not thrown');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertSame('Refusing to delete the OneDrive root folder', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_dot_segments_are_rejected_before_any_request(): void
    {
        Http::fake();

        foreach (['/.', '/Docs/..', '/Docs/../Otro'] as $target) {
            try {
                $this->client()->delete($target);
                $this->fail('OneDriveException was not thrown for ' . $target);
            } catch (OneDriveException $e) {
                $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
                $this->assertSame('Paths may not contain "." or ".." segments', $e->getMessage());
            }
        }

        try {
            $this->client()->createFile('/Docs', '..', 'x');
            $this->fail('OneDriveException was not thrown for a ".." name');
        } catch (OneDriveException $e) {
            $this->assertSame('Paths may not contain "." or ".." segments', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_reads_the_item_then_deletes_it_by_id(): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'DELETE'
            ? Http::response('', 204)
            : Http::response(['id' => 'I9', 'name' => 'a.txt', 'file' => []]));

        $this->client()->delete('/Docs/a.txt');

        $sent = array_map(fn (array $pair) => $pair[0]->method() . ' ' . $pair[0]->url(), Http::recorded()->all());
        $this->assertSame([
            'GET ' . self::GRAPH . '/me/drive/root:/Docs/a.txt:',
            'DELETE ' . self::GRAPH . '/me/drive/items/I9',
        ], $sent);
    }
}
