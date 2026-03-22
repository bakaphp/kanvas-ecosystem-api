<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class FilesystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests upload files to S3 via graphQL mutations.
        // Storage::fake('s3') doesn't fully survive the HTTP request lifecycle
        // in the test helper, causing S3 connections to hang after several tests.
        // Skip when running under paratest (CI) to prevent 100+ minute hangs.
        if (getenv('TEST_TOKEN') !== false || getenv('PARATEST') !== false) {
            $this->markTestSkipped('Filesystem upload tests require dedicated S3 runner');
        }

        Storage::fake('s3');
    }

    protected function fakeS3(): void
    {
        Storage::fake('s3');
    }

    public function testUploadFile(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $this->fakeS3();
        $this->multipartGraphQL($operations, $map, $file)
            ->assertSuccessful()
            ->assertJsonFragment([
                'name' => 'avatar.jpg',
            ]);
    }

    public function testRenameFile(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
                        url
                        id
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $id = $this->multipartGraphQL($operations, $map, $file)
            ->json('data.upload.id');

        $this->assertNotNull($id, 'Upload should return an id');

        $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $id: ID!,
                $name: String!
            ){
                renameFile(
                    id: $id,
                    name: $name
                ) {
                    id
                    name
                }
            }',
            [
                'id' => $id,
                'name' => 'new-avatar.jpg',
            ]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'renameFile' => [
                    'id' => $id,
                    'name' => 'new-avatar.jpg',
                ],
            ],
        ]);
    }

    public function testDeleteFile(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $response = $this->multipartGraphQL($operations, $map, $file)->json();
        $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $uuid: String!
            ){
                deleteFile(
                    uuid: $uuid
                )
            }',
            [
                'uuid' => $response['data']['upload']['uuid'],
            ]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteFile' => true,
            ],
        ]);
    }

    public function testUploadFileOriginalName(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $app = app(Apps::class);
        $app->set('filesystem-preserve-original-filename', true);

        $response = $this->multipartGraphQL($operations, $map, $file);

        $response->assertSuccessful()
            ->assertJsonFragment([
                'name' => 'avatar.jpg',
            ]);
    }

    public function testMultiUploadFile(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($files: [Upload!]!) {
                    multiUpload(files: $files)
                    {
                        uuid,
                        name,
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
            '1' => UploadedFile::fake()->create('bg.jpg'),
        ];

        $this->fakeS3();
        $this->multipartGraphQL($operations, $map, $file)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'avatar.jpg'])
            ->assertJsonFragment(['name' => 'bg.jpg']);
    }

    public function testAttachFile(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $results = $this->multipartGraphQL($operations, $map, $file)->json();
        $filesystemUuid = $results['data']['upload']['uuid'];

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: FilesystemAttachInput!
            ){
                attachFile(
                    input: $input
                )
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystemUuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        );
        $response->assertSuccessful()
        ->assertSee('attachFile');
    }

    public function testGetEntityFiles()
    {
        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query entityFiles ($input: SystemModuleEntityInput!){
                entityFiles(entity: $input) {
                    data {
                        uuid,
                        url,
                        name,
                        field_name
                    },
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
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $results = $this->multipartGraphQL($operations, $map, $file)->json();
        $filesystemUuid = $results['data']['upload']['uuid'];

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: FilesystemAttachInput!
            ){
                attachFile(
                    input: $input
                )
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystemUuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        );

        $results = $response->assertSuccessful()->json();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $uuid: String!
            ){
                deAttachFile(
                    uuid: $uuid
                )
            }',
            [
                'uuid' => $results['data']['attachFile'],
            ]
        )->assertSuccessful();
    }

    public function testDeAttachFiles(): void
    {
        $operations = [
            'query' => /** @lang GraphQL */ '
                mutation ($file: Upload!) {
                    upload(file: $file)
                    {
                        uuid,
                        name,
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
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        $results = $this->multipartGraphQL($operations, $map, $file)->json();
        $filesystemUuid = $results['data']['upload']['uuid'];

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: FilesystemAttachInput!
            ){
                attachFile(
                    input: $input
                )
            }',
            [
                'input' => [
                    'filesystem_uuid' => $filesystemUuid,
                    'field_name' => 'avatar',
                    'system_module_uuid' => get_class(auth()->user()),
                    'entity_id' => auth()->user()->uuid,
                ],
            ]
        );

        $results = $response->assertSuccessful()->json();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $uuids: [String!]!
            ){
                deAttachFiles(
                    uuids: $uuids
                )
            }',
            [
                'uuids' => [
                    $results['data']['attachFile'],
                ],
            ]
        )->assertSuccessful();
    }

    public function testCreateFileSystem(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
        mutation ($input: FilesystemInputUrl!) {
            createFileSystem(input: $input) {
                uuid,
                name,
                url,
                type,
                size
            }
        }
    ', [
            'input' => [
                'url' => 'https://example.com/api/webhooks/upload/test-document.pdf',
                'name' => 'Test Document',
                'attributes' => [
                    'description' => 'Test file created from URL',
                    'category' => 'document',
                ],
            ],
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'createFileSystem' => [
                        'uuid',
                        'name',
                        'url',
                        'type',
                        'size',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'createFileSystem' => [
                        'name' => 'Test Document',
                        'url' => 'https://example.com/api/webhooks/upload/test-document.pdf',
                    ],
                ],
            ]);
    }
}
