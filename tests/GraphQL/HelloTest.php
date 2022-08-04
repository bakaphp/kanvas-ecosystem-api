<?php
declare(strict_types=1);

namespace Tests\GraphQL;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesApplication;

class HelloTest extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

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
