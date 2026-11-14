<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Http\SafeUrlFetcher;
use Exception;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;

/**
 * WhatsApp media is encrypted where it sits: WaSender decrypts it from the key in the payload and
 * hands back a short-lived URL, whose bytes become a file on the message.
 */
class DownloadMessageFileAction
{
    private string $contentKey;

    /**
     * Resolved by known media key, not by position: a group payload often leads with
     * `messageContextInfo` / `senderKeyDistributionMessage`, which carry no media.
     */
    private array $mediaNode;

    public function __construct(
        protected Message $message
    ) {
        if (MessageTypeEnum::tryFrom((string) $this->message->messageType->verb)?->isDocumentType() !== true) {
            throw new Exception('Invalid message type');
        }

        $rawMessage = (array) ($this->message->message['raw_data']['message'] ?? []);
        $contentKey = MessageTypeEnum::mediaKey($rawMessage);
        $mediaNode = MessageTypeEnum::mediaNode($rawMessage);

        if ($contentKey === null || $mediaNode === null) {
            throw new Exception('Message carries no downloadable media node');
        }

        $this->contentKey = $contentKey;
        $this->mediaNode = $mediaNode;
    }

    public function execute(): ?Filesystem
    {
        $mediaKey = $this->mediaNode['mediaKey'] ?? null;
        $url = $this->mediaNode['url'] ?? null;
        $mimeType = $this->mediaNode['mimetype'] ?? null;

        if ($mediaKey === null || $url === null || $mimeType === null) {
            return null;
        }

        $mimeType = (string) $mimeType;
        $fileName = uniqid() . '.' . MediaTypeEnum::extensionForMime($mimeType);

        $decrypted = new MessageService($this->message->app, $this->message->company)->decryptMediaFile([
            'data' => [
                'messages' => [
                    'key' => [
                        'id' => (string) $this->message->message['message_id'],
                    ],
                    'message' => [
                        $this->contentKey => [
                            'url' => (string) $url,
                            'mimetype' => $mimeType,
                            'mediaKey' => (string) $mediaKey,
                            'fileName' => $fileName,
                        ],
                    ],
                ],
            ],
        ]);

        $file = new FilesystemServices($this->message->app, $this->message->company)
            ->createFileSystemFromBase64(
                base64_encode(SafeUrlFetcher::fetch((string) $decrypted['publicUrl'])),
                $fileName,
                $this->message->user
            );

        $this->message->addFile($file, $mimeType);

        return $file;
    }
}
