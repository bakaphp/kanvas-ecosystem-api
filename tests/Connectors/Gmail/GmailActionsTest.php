<?php

declare(strict_types=1);

namespace Tests\Connectors\Gmail;

use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\MessagePartHeader;
use Google\Service\Gmail\Resource\UsersMessages;
use Google\Service\Gmail\Resource\UsersMessagesAttachments;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Gmail\Actions\DownloadAttachmentAction;
use Kanvas\Connectors\Gmail\Actions\ListEmailsAction;
use Kanvas\Connectors\Gmail\Actions\MarkEmailAsReadAction;
use Kanvas\Connectors\Gmail\Actions\ReadEmailDetailsAction;
use Kanvas\Connectors\Gmail\Actions\ReplyToEmailAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Mockery;
use Tests\TestCase;

class GmailActionsTest extends TestCase
{
    public function test_list_emails_returns_id_thread_id_and_subject_for_each_match(): void
    {
        $messagesResource = Mockery::mock(UsersMessages::class);
        $messagesResource->shouldReceive('listUsersMessages')
            ->once()
            ->with('me', ['q' => 'has:attachment is:unread', 'maxResults' => 10])
            ->andReturn(new ListMessagesResponse([
                'messages' => [
                    new Message(['id' => 'MSG_1', 'threadId' => 'THREAD_1']),
                ],
            ]));
        $messagesResource->shouldReceive('get')
            ->once()
            ->with('me', 'MSG_1', ['format' => 'metadata', 'metadataHeaders' => ['Subject']])
            ->andReturn(new Message([
                'payload' => new MessagePart([
                    'headers' => [new MessagePartHeader(['name' => 'Subject', 'value' => 'Invoice #4521'])],
                ]),
            ]));

        $service = Mockery::mock(GmailService::class);
        $service->users_messages = $messagesResource;

        $emails = new ListEmailsAction(
            app: app(Apps::class),
            query: 'has:attachment is:unread',
            service: $service,
        )->execute();

        $this->assertCount(1, $emails);
        $this->assertSame('MSG_1', $emails[0]['id']);
        $this->assertSame('THREAD_1', $emails[0]['thread_id']);
        $this->assertSame('Invoice #4521', $emails[0]['subject']);
    }

    public function test_read_email_details_extracts_headers_body_and_attachments(): void
    {
        $bodyData = strtr(base64_encode('Please see attached invoice.'), '+/', '-_');

        $messagesResource = Mockery::mock(UsersMessages::class);
        $messagesResource->shouldReceive('get')
            ->once()
            ->with('me', 'MSG_1', ['format' => 'full'])
            ->andReturn(new Message([
                'payload' => new MessagePart([
                    'headers' => [
                        new MessagePartHeader(['name' => 'From', 'value' => 'vendor@windwalk.com']),
                        new MessagePartHeader(['name' => 'Date', 'value' => 'Tue, 11 Aug 2026 09:00:00 -0400']),
                        new MessagePartHeader(['name' => 'Subject', 'value' => 'Invoice #4521']),
                    ],
                    'parts' => [
                        new MessagePart(['mimeType' => 'text/plain', 'body' => new MessagePartBody(['data' => $bodyData])]),
                        new MessagePart([
                            'mimeType' => 'application/pdf',
                            'filename' => 'invoice-4521.pdf',
                            'body' => new MessagePartBody(['attachmentId' => 'ATTACH_1']),
                        ]),
                    ],
                ]),
            ]));

        $service = Mockery::mock(GmailService::class);
        $service->users_messages = $messagesResource;

        $details = new ReadEmailDetailsAction(app(Apps::class), 'MSG_1', $service)->execute();

        $this->assertSame('vendor@windwalk.com', $details['from']);
        $this->assertSame('Invoice #4521', $details['subject']);
        $this->assertSame('Please see attached invoice.', $details['body']);
        $this->assertCount(1, $details['attachments']);
        $this->assertSame('invoice-4521.pdf', $details['attachments'][0]['filename']);
        $this->assertSame('ATTACH_1', $details['attachments'][0]['attachment_id']);
    }

    public function test_download_attachment_saves_it_as_a_kanvas_filesystem_entry(): void
    {
        $attachmentData = strtr(base64_encode('%PDF-1.4 fake pdf bytes'), '+/', '-_');

        $attachmentsResource = Mockery::mock(UsersMessagesAttachments::class);
        $attachmentsResource->shouldReceive('get')
            ->once()
            ->with('me', 'MSG_1', 'ATTACH_1')
            ->andReturn(new MessagePartBody(['data' => $attachmentData]));

        $service = Mockery::mock(GmailService::class);
        $service->users_messages_attachments = $attachmentsResource;

        $storedFilesystem = new Filesystem([
            'name' => 'invoice-4521.pdf',
            'url' => 'https://cdn.example.com/invoice-4521.pdf',
            'size' => '23',
        ]);
        $storedFilesystem->id = 999;

        $filesystemServices = Mockery::mock(FilesystemServices::class);
        $filesystemServices->shouldReceive('createFileSystemFromBase64')
            ->once()
            ->with(Mockery::type('string'), 'invoice-4521.pdf', Mockery::any())
            ->andReturn($storedFilesystem);

        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $result = new DownloadAttachmentAction(
            app: $app,
            company: $company,
            user: static::$cachedUser,
            messageId: 'MSG_1',
            attachmentId: 'ATTACH_1',
            filename: 'invoice-4521.pdf',
            service: $service,
            filesystemServices: $filesystemServices,
        )->execute();

        $this->assertSame(999, $result['filesystem_id']);
        $this->assertSame('invoice-4521.pdf', $result['filename']);
        $this->assertSame('https://cdn.example.com/invoice-4521.pdf', $result['url']);
        $this->assertSame(23, $result['size']);
    }

    public function test_mark_email_as_read_removes_the_unread_label(): void
    {
        $messagesResource = Mockery::mock(UsersMessages::class);
        $messagesResource->shouldReceive('modify')
            ->once()
            ->with(
                'me',
                'MSG_1',
                Mockery::on(fn ($body) => $body->getRemoveLabelIds() === ['UNREAD']),
            )
            ->andReturn(new Message(['id' => 'MSG_1']));

        $service = Mockery::mock(GmailService::class);
        $service->users_messages = $messagesResource;

        $result = new MarkEmailAsReadAction(app(Apps::class), 'MSG_1', $service)->execute();

        $this->assertSame('MSG_1', $result['message_id']);
    }

    public function test_reply_to_email_sends_an_internal_only_threaded_reply(): void
    {
        $messagesResource = Mockery::mock(UsersMessages::class);
        $messagesResource->shouldReceive('get')
            ->once()
            ->with('me', 'MSG_1', ['format' => 'metadata', 'metadataHeaders' => ['Subject', 'Message-ID']])
            ->andReturn(new Message([
                'threadId' => 'THREAD_1',
                'payload' => new MessagePart([
                    'headers' => [
                        new MessagePartHeader(['name' => 'Subject', 'value' => 'Invoice #4521']),
                        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<original@vendor.com>']),
                    ],
                ]),
            ]));

        $messagesResource->shouldReceive('send')
            ->once()
            ->with('me', Mockery::on(function (Message $message): bool {
                $decoded = (string) base64_decode(strtr((string) $message->getRaw(), '-_', '+/'));

                return $message->getThreadId() === 'THREAD_1'
                    && str_contains($decoded, 'To: approver@kanvas.test')
                    && str_contains($decoded, 'Subject: Re: Invoice #4521')
                    && str_contains($decoded, 'In-Reply-To: <original@vendor.com>')
                    && str_contains($decoded, 'Approved by Jane Doe on 2026-08-19');
            }))
            ->andReturn(new Message(['id' => 'SENT_1', 'threadId' => 'THREAD_1']));

        $service = Mockery::mock(GmailService::class);
        $service->users_messages = $messagesResource;

        $result = new ReplyToEmailAction(
            app(Apps::class),
            'MSG_1',
            ['approver@kanvas.test'],
            'Approved by Jane Doe on 2026-08-19',
            $service,
        )->execute();

        $this->assertSame('SENT_1', $result['message_id']);
        $this->assertSame('THREAD_1', $result['thread_id']);
    }
}
