<?php

declare(strict_types=1);

namespace Tests\Connectors\Slack;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class ClientTest extends TestCase
{
    public function test_upload_file_reserves_a_url_uploads_the_bytes_then_completes_the_upload(): void
    {
        Http::fake([
            'slack.com/api/files.getUploadURLExternal' => Http::response([
                'ok' => true,
                'upload_url' => 'https://files.slack.com/upload/v1/abc123',
                'file_id' => 'F123',
            ]),
            'files.slack.com/upload/v1/abc123' => Http::response('', 200),
            'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
        ]);

        new Client('xoxb-test-token')->uploadFile('D123', 'invoice.pdf', '%PDF-1.4 fake bytes', 'Here is the invoice');

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'files.getUploadURLExternal')
                && $request['filename'] === 'invoice.pdf'
                && $request['length'] === '19'
        );
        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://files.slack.com/upload/v1/abc123'
                && $request->body() === '%PDF-1.4 fake bytes'
        );
        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'files.completeUploadExternal')
                && $request['channel_id'] === 'D123'
                && $request['initial_comment'] === 'Here is the invoice'
        );
    }

    public function test_upload_file_throws_when_slack_does_not_return_an_upload_url(): void
    {
        Http::fake([
            'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true]),
        ]);

        $this->expectException(ValidationException::class);

        new Client('xoxb-test-token')->uploadFile('D123', 'invoice.pdf', 'bytes');
    }

    public function test_upload_file_throws_when_the_upload_put_fails(): void
    {
        Http::fake([
            'slack.com/api/files.getUploadURLExternal' => Http::response([
                'ok' => true,
                'upload_url' => 'https://files.slack.com/upload/v1/abc123',
                'file_id' => 'F123',
            ]),
            'files.slack.com/upload/v1/abc123' => Http::response('', 500),
        ]);

        $this->expectException(ValidationException::class);

        new Client('xoxb-test-token')->uploadFile('D123', 'invoice.pdf', 'bytes');
    }
}
