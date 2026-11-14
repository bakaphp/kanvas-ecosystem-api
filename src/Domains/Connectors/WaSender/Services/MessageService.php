<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Kanvas\Connectors\WaSender\Client;
use Throwable;

/**
 * Outbound WhatsApp. **No many-recipient send belongs here** — this is an unofficial client and
 * WhatsApp restricts numbers for bulk messaging. The supported shapes are a 1:1 reply and a group
 * reply when the agent is mentioned, both delayed by the burst window.
 */
class MessageService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected bool $outbound = false
    ) {
        $this->client = new Client($app, $company, $this->outbound);
    }

    /**
     * `$to` is a number in E.164 digits or a group JID, here and in every sender below.
     */
    public function sendTextMessage(string $to, string $text): array
    {
        return $this->client->post('/api/send-message', [
            'to' => $to,
            'text' => $text,
        ]);
    }

    public function sendImageMessage(string $to, string $imageUrl, ?string $caption = null): array
    {
        return $this->sendMedia($to, 'imageUrl', $imageUrl, $caption);
    }

    public function sendVideoMessage(string $to, string $videoUrl, ?string $caption = null): array
    {
        return $this->sendMedia($to, 'videoUrl', $videoUrl, $caption);
    }

    public function sendDocumentMessage(string $to, string $documentUrl, ?string $caption = null): array
    {
        return $this->sendMedia($to, 'documentUrl', $documentUrl, $caption);
    }

    public function sendAudioMessage(string $to, string $audioUrl): array
    {
        return $this->sendMedia($to, 'audioUrl', $audioUrl);
    }

    public function decryptMediaFile(array $payload): array
    {
        // Decryption can be repeated safely; outbound sends must not inherit these retries.
        return retry(
            times: [1000, 2000],
            callback: fn (): array => $this->client->post('/api/decrypt-media', $payload),
            when: fn (Throwable $e): bool => $e instanceof ServerException || $e instanceof ConnectException,
        );
    }

    private function sendMedia(
        string $to,
        string $urlKey,
        string $url,
        ?string $caption = null
    ): array {
        return $this->client->post('/api/send-message', array_filter(
            [
                'to' => $to,
                $urlKey => $url,
                'text' => $caption,
            ],
            static fn (?string $value): bool => $value !== null
        ));
    }
}
