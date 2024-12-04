<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class GraphQLVersionTest extends TestCase
{
    protected string $graphqlVersion = 'graphql-2025-01';

    public function testHelloKanvas(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
        {
            hello
            }
        ');
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('hello', $response['data']);
        $this->assertEquals('Hello Ecosystem 2025-01!', $response['data']['hello']);
    }
}
