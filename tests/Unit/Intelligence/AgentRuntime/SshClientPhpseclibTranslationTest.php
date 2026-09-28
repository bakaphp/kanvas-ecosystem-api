<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence\AgentRuntime;

use BadMethodCallException;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Override;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Net\SFTP;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the v3 -> v4 translation layer in SshClient. phpseclib4 turned put/mkdir into
 * void-plus-throw and made get() return null once it has written to a local file, so the
 * bool contract these helpers expose is now hand-rolled rather than passed through.
 * A straight namespace bump would compile and then break every one of these at runtime.
 */
final class SshClientPhpseclibTranslationTest extends TestCase
{
    private function clientWith(SFTP $sftp): SshClient
    {
        $client = new class () extends SshClient {
            // Skip the parent constructor — it resolves a ProviderConfig and would open a socket.
            public function __construct()
            {
            }

            #[Override]
            public static function makeProviderConfig(): ProviderConfig
            {
                throw new BadMethodCallException('not needed for the transfer helpers');
            }

            #[Override]
            protected function buildFromCompanyConfig(CompanyInterface $company): void
            {
            }

            public function setSftp(SFTP $sftp): void
            {
                $this->sftp = $sftp;
            }
        };

        $client->setSftp($sftp);

        return $client;
    }

    public function testWriteFileReturnsTrueWhenPutReturnsVoid(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects($this->once())->method('mkdir');
        $sftp->expects($this->once())
            ->method('put')
            ->with('/workspace/agent/config.json', '{"a":1}', SFTP::SOURCE_STRING);

        $this->assertTrue(
            $this->clientWith($sftp)->writeFile('/workspace/agent/config.json', '{"a":1}')
        );
    }

    public function testWriteFileReturnsFalseWhenPutThrows(): void
    {
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('put')->willThrowException(new FileSystemException('permission denied'));

        $this->assertFalse(
            $this->clientWith($sftp)->writeFile('/workspace/agent/config.json', '{"a":1}')
        );
    }

    public function testUploadFromFileStreamsWithSourceLocalFile(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects($this->once())
            ->method('put')
            ->with('/tmp/archive.tar.gz', '/local/archive.tar.gz', SFTP::SOURCE_LOCAL_FILE);

        $this->assertTrue(
            $this->clientWith($sftp)->uploadFromFile('/tmp/archive.tar.gz', '/local/archive.tar.gz')
        );
    }

    // The v4 trap: a successful download reports null because the bytes went to disk, not to the return.
    public function testDownloadToFileReportsSuccessEvenThoughGetReturnsNull(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects($this->once())
            ->method('get')
            ->with('/tmp/archive.tar.gz', '/local/archive.tar.gz')
            ->willReturn(null);

        $this->assertTrue(
            $this->clientWith($sftp)->downloadToFile('/tmp/archive.tar.gz', '/local/archive.tar.gz')
        );
    }

    public function testDownloadToFileReturnsFalseWhenGetThrows(): void
    {
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('get')->willThrowException(new FileSystemException('no such file'));

        $this->assertFalse(
            $this->clientWith($sftp)->downloadToFile('/tmp/archive.tar.gz', '/local/archive.tar.gz')
        );
    }

    public function testReadFileReturnsContentsAndEmptyStringWhenMissing(): void
    {
        $found = $this->createStub(SFTP::class);
        $found->method('get')->willReturn('{"agents":{}}');
        $this->assertSame('{"agents":{}}', $this->clientWith($found)->readFile('/home/agent/.openclaw/openclaw.json'));

        $missing = $this->createStub(SFTP::class);
        $missing->method('get')->willThrowException(new FileSystemException('no such file'));
        $this->assertSame('', $this->clientWith($missing)->readFile('/home/agent/.openclaw/openclaw.json'));
    }

    // exec() returns null on the PTY branch despite its @psalm-return saying otherwise.
    public function testExecCoercesNullToEmptyString(): void
    {
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('exec')->willReturn(null);

        $this->assertSame('', $this->clientWith($sftp)->exec('docker ps'));
    }
}
