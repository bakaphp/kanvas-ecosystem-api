<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Client;
use Tests\TestCase;

final class ClientCaFileTest extends TestCase
{
    private const string PEM = "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n";

    protected function tearDown(): void
    {
        @unlink(Client::caFile(self::PEM));
        @unlink(Client::caFile(self::PEM . "\n"));

        parent::tearDown();
    }

    public function testPathIsReturnedAsIs(): void
    {
        $this->assertSame('/etc/ssl/rds.pem', Client::caFile('/etc/ssl/rds.pem'));
    }

    public function testPemContentIsWrittenToAFile(): void
    {
        $path = Client::caFile(self::PEM);

        $this->assertFileExists($path);
        $this->assertSame(self::PEM, file_get_contents($path));
    }

    public function testSameContentReusesTheFileAndNewContentGetsAnother(): void
    {
        $first = Client::caFile(self::PEM);

        $this->assertSame($first, Client::caFile(self::PEM));
        $this->assertNotSame($first, Client::caFile(self::PEM . "\n"));
    }

    public function testFileIsRecreatedWhenRemoved(): void
    {
        $path = Client::caFile(self::PEM);
        unlink($path);

        $this->assertSame($path, Client::caFile(self::PEM));
        $this->assertFileExists($path);
    }
}
