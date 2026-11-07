<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use ReflectionProperty;
use Tests\TestCase;

class OneDriveRootFolderTest extends TestCase
{
    use DatabaseTransactions;

    private const string GRAPH = 'https://graph.microsoft.com/v1.0';

    private function client(): OneDriveClient
    {
        return new OneDriveClient('token', rootFolder: '/Clientes');
    }

    private function item(string $id, string $name, string $parent, bool $folder = false): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'eTag' => '"e"',
            ($folder ? 'folder' : 'file') => [],
            'parentReference' => ['path' => $parent],
        ];
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

    public function test_the_drive_root_is_the_configured_folder_and_paths_come_back_relative(): void
    {
        Http::fake(['*' => Http::response(['value' => [
            $this->item('A', 'a.docx', '/drive/root:/Clientes'),
            $this->item('B', 'ACME', '/drive/root:/Clientes', folder: true),
        ]])]);

        $items = $this->client()->listFolder(null);

        $this->assertSame(['/a.docx', '/ACME'], array_column($items, 'path'));
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH . '/me/drive/root:/Clientes:/children');
    }

    public function test_a_path_is_resolved_inside_the_folder(): void
    {
        Http::fake(['*' => Http::response($this->item('X', 'x.docx', '/drive/root:/Clientes/ACME'))]);

        $item = $this->client()->getItem('/ACME/x.docx');

        $this->assertSame('/ACME/x.docx', $item['path']);
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH . '/me/drive/root:/Clientes/ACME/x.docx:');
    }

    public function test_a_dot_segment_cannot_climb_out(): void
    {
        Http::fake();

        $this->assertReason(OneDriveErrorReasonEnum::REQUEST_FAILED, fn () => $this->client()->listFolder('/../Privado'));
        Http::assertNothingSent();
    }

    public function test_an_id_inside_the_folder_is_allowed(): void
    {
        Http::fake(['*' => Http::response($this->item('X', 'x.docx', '/drive/root:/clientes/ACME'))]);

        $this->assertSame('/ACME/x.docx', $this->client()->getItem('X')['path']);
    }

    public function test_an_id_outside_the_folder_is_refused(): void
    {
        foreach (['/drive/root:/Privado', '/drive/root:/ClientesViejos', '/drive/root:'] as $parent) {
            Http::fake(['*' => Http::response($this->item('X', 'x.docx', $parent))]);

            $this->assertReason(OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER, fn () => $this->client()->getItem('X'));
        }
    }

    public function test_the_drive_root_by_id_is_refused(): void
    {
        Http::fake(['*' => Http::response(['id' => 'ROOT', 'name' => 'root', 'folder' => [], 'root' => []])]);

        $this->assertReason(OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER, fn () => $this->client()->listFolder('ROOT'));
        Http::assertSentCount(1);
    }

    public function test_writes_by_id_outside_the_folder_send_nothing_else(): void
    {
        Http::fake(['*' => Http::response($this->item('X', 'x.docx', '/drive/root:/Privado'))]);

        $this->assertReason(OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER, fn () => $this->client()->replace('X', 'data', '"e"'));
        $this->assertReason(OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER, fn () => $this->client()->delete('X'));
        $this->assertReason(OneDriveErrorReasonEnum::OUTSIDE_ALLOWED_FOLDER, fn () => $this->client()->createFolder('X', 'Nueva'));

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_the_configured_folder_itself_cannot_be_deleted(): void
    {
        Http::fake(['*' => Http::response($this->item('C', 'Clientes', '/drive/root:', folder: true))]);

        $this->assertReason(OneDriveErrorReasonEnum::REQUEST_FAILED, fn () => $this->client()->delete('/'));
        $this->assertReason(OneDriveErrorReasonEnum::REQUEST_FAILED, fn () => $this->client()->delete('C'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_creating_in_the_root_creates_inside_the_folder(): void
    {
        Http::fake(['*' => Http::response($this->item('N', 'Nueva', '/drives/ABC/root:/Clientes', folder: true))]);

        $item = $this->client()->createFolder(null, 'Nueva');

        $this->assertSame('/Nueva', $item['path']);
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH . '/me/drive/root:/Clientes:/children');
    }

    public function test_search_only_returns_items_inside_the_folder(): void
    {
        Http::fake(['*' => Http::response(['value' => [
            $this->item('A', 'a.docx', '/drive/root:/Clientes/ACME'),
            $this->item('B', 'b.docx', '/drive/root:/Privado'),
            $this->item('C', 'c.docx', '/drive/root:/Clientes/Otro'),
        ]])]);

        $this->assertSame(['/ACME/a.docx', '/Otro/c.docx'], array_column($this->client()->search('docx'), 'path'));
        $this->assertSame(['/ACME/a.docx'], array_column($this->client()->search('docx', folder: '/ACME'), 'path'));
    }

    public function test_for_company_reads_the_configured_folder(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = '-' . $app->getId() . '-' . $company->getId();
        $keys = [ConfigurationEnum::ACCESS_TOKEN, ConfigurationEnum::REFRESH_TOKEN, ConfigurationEnum::TOKEN_EXPIRES_AT, ConfigurationEnum::ONEDRIVE_ROOT_FOLDER];
        $original = [];
        foreach ($keys as $case) {
            $original[$case->value] = $company->get($case->value . $suffix);
        }

        try {
            $company->set(ConfigurationEnum::ACCESS_TOKEN->value . $suffix, 'token');
            $company->set(ConfigurationEnum::REFRESH_TOKEN->value . $suffix, 'refresh');
            $company->set(ConfigurationEnum::TOKEN_EXPIRES_AT->value . $suffix, now()->addHour()->toDateTimeString());
            $rootFolder = new ReflectionProperty(OneDriveClient::class, 'rootFolder');

            foreach (['Clientes/ACME/' => '/Clientes/ACME', ' / ' => null, '' => null] as $setting => $expected) {
                $company->set(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER->value . $suffix, $setting);

                $this->assertSame($expected, $rootFolder->getValue(OneDriveClient::forCompany($app, $company)));
            }
        } finally {
            foreach ($keys as $case) {
                $value = $original[$case->value];
                $value === null ? $company->del($case->value . $suffix) : $company->set($case->value . $suffix, $value);
            }
        }
    }
}
