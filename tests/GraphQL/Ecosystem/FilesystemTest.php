<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FilesystemTest extends TestCase
{
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

        $this->multipartGraphQL($operations, $map, $file)
            ->assertJson([
                'data' => [
                    'upload' => [
                        'name' => 'avatar.jpg',
                    ],
                ],
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

        $this->multipartGraphQL($operations, $map, $file)
            ->assertJson([
                'data' => [
                    'multiUpload' => [
                        [
                            'name' => 'avatar.jpg',
                        ],
                        [
                            'name' => 'bg.jpg',
                        ],
                    ],
                ],
            ]);
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
                    'entity_id' =>  auth()->user()->uuid,
                ],
            ]
        );
        $response->assertSuccessful()
        ->assertSee('attachFile');
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
                    'entity_id' =>  auth()->user()->uuid,
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
        );
    }
}
