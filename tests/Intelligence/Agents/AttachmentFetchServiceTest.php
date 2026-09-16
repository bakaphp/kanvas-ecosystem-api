<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Baka\Http\Exceptions\SsrfException;
use ErrorException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Services\AttachmentFetchService;
use Tests\TestCase;

class AttachmentFetchServiceTest extends TestCase
{
    public function testRejectedUrlReturnsNullAndIsLoggedNotReported(): void
    {
        Exceptions::fake();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context['exception'] === SsrfException::class);

        $this->assertNull(AttachmentFetchService::fetch('http://127.0.0.1/receipt.png'));

        Exceptions::assertNothingReported();
    }

    public function testUnexpectedFailureIsStillReported(): void
    {
        Exceptions::fake();

        $this->assertNull(AttachmentFetchService::fetch('/nonexistent/' . uniqid() . '.png'));

        Exceptions::assertReported(ErrorException::class);
    }

    public function testLocalFileIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attach_fetch_');
        file_put_contents($path, 'bytes');

        $this->assertSame('bytes', AttachmentFetchService::fetch($path));

        unlink($path);
    }

    public function testOnlyClientErrorsAndSsrfRejectionsCountAsRejectedSources(): void
    {
        $request = new Request('GET', 'https://upload.wikimedia.org/a.jpg');

        $this->assertTrue(AttachmentFetchService::isRejectedSource(new ClientException('Forbidden', $request, new Response(403))));
        $this->assertTrue(AttachmentFetchService::isRejectedSource(new SsrfException('private host')));
        $this->assertFalse(AttachmentFetchService::isRejectedSource(new ServerException('Down', $request, new Response(500))));
        $this->assertFalse(AttachmentFetchService::isRejectedSource(new ConnectException('Timeout', $request)));
    }

    public function testUnavailableNoteNamesTheSource(): void
    {
        $this->assertStringContainsString(
            'https://example.com/a.jpg',
            AttachmentFetchService::unavailableNote('https://example.com/a.jpg'),
        );
    }
}
