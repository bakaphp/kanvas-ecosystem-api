<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Tests\TestCase;

class FilesystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip under paratest — multipartGraphQL file uploads hang with concurrent workers
        if (getenv('TEST_TOKEN') !== false) {
            $this->markTestSkipped('Filesystem tests skipped under paratest');
        }
    }

    private function uploadFileDirectly(string $filename = 'avatar.jpg'): Filesystem
    {
        $file = UploadedFile::fake()->create($filename);
        $user = auth()->user();
        $app = app(Apps::class);

        $createFileSystem = new CreateFilesystemAction($file, $user, $app);
        $createFileSystem->runWorkflow = false;

        return $createFileSystem->execute(
            'http://localhost/testing/' . $file->hashName(),
            'uploads'
        );
    }

    public function testUploadFile(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $this->assertNotNull($filesystem->id);
        $this->assertEquals('avatar.jpg', $filesystem->name);
        $this->assertNotNull($filesystem->uuid);
        $this->assertNotNull($filesystem->url);
    }

    public function testUploadFileViaGraphQL(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($files: [Upload!]!) {
                    multiUpload(files: $files) {
                        uuid
                        name
                        url
                    }
                }
            ',
            'variables' => [
                'files' => [null, null],
            ],
        ];

        $map = [
            '0' => ['variables.files.0'],
            '1' => ['variables.files.1'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('avatar.jpg'),
            '1' => UploadedFile::fake()->create('document.pdf', 500),
        ];

        $this->multipartGraphQL($operations, $map, $file)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'avatar.jpg'])
            ->assertJsonFragment(['name' => 'document.pdf']);
    }

    public function testSingleUploadViaGraphQL(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file) {
                        uuid
                        name
                        url
                    }
                }
            ',
            'variables' => [
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('single-test.jpg'),
        ];

        $this->multipartGraphQL($operations, $map, $file)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'single-test.jpg']);
    }

    public function testRenameFile(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!, $name: String!) {
                renameFile(id: $id, name: $name) {
                    id
                    name
                }
            }',
            [
                'id' => $filesystem->id,
                'name' => 'new-avatar.jpg',
            ]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'renameFile' => [
                    'id' => (string) $filesystem->id,
                    'name' => 'new-avatar.jpg',
                ],
            ],
        ]);
    }

    public function testDeleteFile(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $this->graphQL(/** @lang GraphQL */ '
            mutation($uuid: String!) {
                deleteFile(uuid: $uuid)
            }',
            ['uuid' => $filesystem->uuid]
        )->assertSuccessful()
        ->assertJson([
            'data' => ['deleteFile' => true],
        ]);
    }

    public function testUploadFileOriginalName(): void
    {
        $app = app(Apps::class);
        $app->set('filesystem-preserve-original-filename', true);

        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $this->assertEquals('avatar.jpg', $filesystem->name);
    }

    public function testMultiUploadFile(): void
    {
        $file1 = $this->uploadFileDirectly('avatar.jpg');
        $file2 = $this->uploadFileDirectly('bg.jpg');

        $this->assertEquals('avatar.jpg', $file1->name);
        $this->assertEquals('bg.jpg', $file2->name);
        $this->assertNotEquals($file1->uuid, $file2->uuid);
    }

    public function testAttachFile(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $this->graphQL(/** @lang GraphQL */ '
            mutation($input: FilesystemAttachInput!) {
                attachFile(input: $input)
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystem->uuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        )->assertSuccessful()
        ->assertSee('attachFile');
    }

    public function testGetEntityFiles()
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query entityFiles($input: SystemModuleEntityInput!) {
                entityFiles(entity: $input) {
                    data {
                        uuid
                        url
                        name
                        field_name
                    }
                    paginatorInfo {
                        currentPage
                        lastPage
                    }
                }
            }',
            [
                'input' => [
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        );

        $this->assertArrayHasKey('data', $response);
    }

    public function testDeAttachFile(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $attachResponse = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: FilesystemAttachInput!) {
                attachFile(input: $input)
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystem->uuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        )->assertSuccessful();

        $attachUuid = $attachResponse->json('data.attachFile');

        $this->graphQL(/** @lang GraphQL */ '
            mutation($uuid: String!) {
                deAttachFile(uuid: $uuid)
            }',
            ['uuid' => $attachUuid]
        )->assertSuccessful();
    }

    public function testDeAttachFiles(): void
    {
        $filesystem = $this->uploadFileDirectly('avatar.jpg');

        $attachResponse = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: FilesystemAttachInput!) {
                attachFile(input: $input)
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystem->uuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        )->assertSuccessful();

        $attachUuid = $attachResponse->json('data.attachFile');

        $this->graphQL(/** @lang GraphQL */ '
            mutation($uuids: [String!]!) {
                deAttachFiles(uuids: $uuids)
            }',
            ['uuids' => [$attachUuid]]
        )->assertSuccessful();
    }

    public function testCreateFileSystem(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation($input: FilesystemInputUrl!) {
                createFileSystem(input: $input) {
                    uuid
                    name
                    url
                    type
                    size
                }
            }',
            [
                'input' => [
                    'url' => 'https://example.com/test-document.pdf',
                    'name' => 'Test Document',
                    'attributes' => [
                        'description' => 'Test file created from URL',
                        'category' => 'document',
                    ],
                ],
            ]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'createFileSystem' => [
                    'name' => 'Test Document',
                    'url' => 'https://example.com/test-document.pdf',
                ],
            ],
        ]);
    }
}
