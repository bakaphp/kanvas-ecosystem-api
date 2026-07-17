<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Baka\Http\Exceptions\SsrfException;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Templates\Blank;
use ReflectionMethod;
use Tests\TestCase;

class AnonymousNotificationAttachmentTest extends TestCase
{
    /**
     * The anonymous-notification mutation used to silently drop the `attachment`
     * argument (the resolver built Blank with 4 args, never forwarding it). This
     * asserts the attachment now reaches the notification's pathAttachment so the
     * cert/summary a local script uploads and passes by URL actually rides along.
     */
    public function testAnonymousNotificationForwardsAttachment(): void
    {
        $app = app(Apps::class);
        $email = 'participant.' . uniqid() . '@example.com';
        $certUrl = 'https://files.example.com/certificates/PA-123.pdf';
        $summaryUrl = 'https://files.example.com/events/VE7106-resumen.pdf';

        Notification::fake();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendAnonymous(
                $template_name: String!
                $data: Mixed!
                $email: Email!
                $subject: String!
                $attachment: [String]
            ) {
                sendNotificationAnonymousBaseOnTemplate(
                    template_name: $template_name
                    data: $data
                    email: $email
                    subject: $subject
                    attachment: $attachment
                )
            }
            ',
            [
                'template_name' => 'blank',
                'data' => ['participant_name' => 'Ada Lovelace'],
                'email' => $email,
                'subject' => 'Certificado — VE7106',
                'attachment' => [$certUrl, $summaryUrl],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertSuccessful();

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification, array $channels, object $notifiable) use ($email, $certUrl, $summaryUrl): bool {
                return $notifiable->routes['mail'] === $email
                    && $notification->pathAttachment === [$certUrl, $summaryUrl];
            }
        );
    }

    /**
     * A local/server path attachment is attached directly by path.
     */
    public function testLocalPathAttachmentIsAttachedByPath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cert') . '.pdf';
        file_put_contents($tmpFile, '%PDF-1.4 test');

        try {
            $notification = $this->makeBlankWithAttachments([$tmpFile]);
            $mailMessage = new KanvasMailable([], 'body');

            $this->applyAttachments($notification, $mailMessage);

            $this->assertCount(1, $mailMessage->attachments);
            $this->assertSame($tmpFile, $mailMessage->attachments[0]['file']);
            $this->assertCount(0, $mailMessage->rawAttachments);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * A remote (http/https) attachment is never treated as a local path — it is
     * routed through the SSRF-guarded fetcher, which rejects internal targets like
     * the cloud-metadata address before any connection is made.
     */
    public function testRemoteAttachmentGoesThroughSsrfGuard(): void
    {
        $notification = $this->makeBlankWithAttachments(['http://169.254.169.254/latest/meta-data/cert.pdf']);
        $mailMessage = new KanvasMailable([], 'body');

        $this->expectException(SsrfException::class);

        $this->applyAttachments($notification, $mailMessage);
    }

    private function makeBlankWithAttachments(array $attachments): Blank
    {
        return new Blank(
            'blank',
            ['participant_name' => 'Ada Lovelace'],
            ['mail'],
            auth()->user(),
            $attachments
        );
    }

    private function applyAttachments(Blank $notification, KanvasMailable $mailMessage): void
    {
        $method = new ReflectionMethod($notification, 'applyAttachments');
        $method->setAccessible(true);
        $method->invoke($notification, $mailMessage);
    }
}
