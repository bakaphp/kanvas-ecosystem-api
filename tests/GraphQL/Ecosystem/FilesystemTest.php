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
                        'name' => 'avatar.jpg'
                    ],
                ],
            ]);
    }
}
