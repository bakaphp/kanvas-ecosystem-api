<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Recombee;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Exceptions\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

final class RecombeeClientRegionTest extends TestCase
{
    /**
     * `$app->set($key, getenv('MISSING'))` stores `false`, which reads back as int 0 — that reached
     * the SDK's region map as key 0 and blew up as `ErrorException: Undefined array key 0` before it
     * could throw its own "Region is unknown".
     */
    public function testAnUnusableRegionFallsBackInsteadOfFatallingInTheSdk(): void
    {
        foreach (['0', '', 'not-a-region'] as $region) {
            $client = new Client(
                app(Apps::class),
                'test-database',
                'test-api-key',
                $region
            );

            $this->assertSame('rapi-ca-east.recombee.com', $this->baseUriOf($client));
        }
    }

    public function testAKnownRegionIsUsedRegardlessOfCasingOrPadding(): void
    {
        $client = new Client(
            app(Apps::class),
            'test-database',
            'test-api-key',
            ' US-West '
        );

        $this->assertSame('rapi-us-west.recombee.com', $this->baseUriOf($client));
    }

    public function testMissingCredentialsStillFail(): void
    {
        $this->expectException(ValidationException::class);

        new Client(
            app(Apps::class),
            '',
            '',
            'ca-east'
        );
    }

    private function baseUriOf(Client $client): string
    {
        $baseUri = new ReflectionProperty($client->getClient(), 'base_uri');

        return (string) $baseUri->getValue($client->getClient());
    }
}
