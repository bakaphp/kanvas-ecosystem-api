<?php
declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class HelloTest extends TestCase
{
    public function testHelloKanvas() : void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
        {
            hello
            }
        ');
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('hello', $response['data']);
        $this->assertEquals('Kanvas Ecosystem!', $response['data']['hello']);
    }
}
