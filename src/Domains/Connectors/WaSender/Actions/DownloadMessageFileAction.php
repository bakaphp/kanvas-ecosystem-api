<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Http\SafeUrl;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class DownloadMessageFileAction
{
    /**
     * Only where the family default would be wrong — a png named `.jpg` is a lie WordPress will
     * later trip over.
     */
    private const array EXTENSION_BY_MIME = [
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'application/pdf' => 'pdf',
    ];

    protected FilesystemServices $filesystemService;
    protected string $messageTypeKey;

    /**
     * The unwrapped media node. Resolved by known media key rather than by position: group
     * payloads lead with `messageContextInfo` / `senderKeyDistributionMessage` often enough that
     * `array_key_first` picked a metadata sibling and the download read a mediaKey off it.
     */
    protected array $mediaNode;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected ?Agent $agent = null,
    ) {
        if (! MessageTypeEnum::isDocumentType($this->message->messageType->verb)) {
            throw new Exception('Invalid message type');
        }

        $this->filesystemService = new FilesystemServices(
            $this->message->app,
            $this->message->company ?? null
        );

        $rawMessage = (array) ($this->message->message['raw_data']['message'] ?? []);
        $mediaKey = MessageTypeEnum::mediaKey($rawMessage);

        if ($mediaKey === null) {
            throw new Exception('Message carries no downloadable media node');
        }

        $this->messageTypeKey = $mediaKey;
        $this->mediaNode = (array) MessageTypeEnum::unwrap($rawMessage)[$mediaKey];
    }

    public function execute(array $params = []): ?Filesystem
    {
        /*  $mediaKey = 'SBqPa+ZHCoVLDdbSve+7sUbh+YDHyf+XoRuuvxdXj48=';
         $url = 'https://mmg.whatsapp.net/o1/v/t62.7118-24/f2/m231/AQMBvwf5EiyXOdUQvtUDTcmS4ke_uYG1VJplhYBV8CejeAVhezKVUmB-cjS8kSl69SH0DeZss_i1c9h3ft18D5v7WncL7VF1BXO3zFwjGQ?ccb=9-4&oh=01_Q5Aa1gF5H2Hatef7zk77Pi86h_nQGkPchoxSCFh_amWPEp7vvg&oe=684B23A9&_nc_sid=e6ed6c&mms3=true';

         decryptWhatsAppMedia($mediaKey, $url, DIR . '/decrypted_image.jpg');  */
        $mediaKey = $this->mediaNode['mediaKey'] ?? null;
        $url = $this->mediaNode['url'] ?? null;
        $mimeType = $this->mediaNode['mimetype'] ?? null;

        if ($mediaKey === null || $url === null || $mimeType === null) {
            return null;
        }

        return $this->processWhatsAppMedia(
            (string) $this->message->message['message_id'],
            (string) $mediaKey,
            (string) $url,
            (string) $mimeType
        );
    }

    protected function decryptWhatsAppMedia(
        string $messageId,
        string $mediaKey,
        string $url,
        string $type = 'image'
    ): array {

        $payload = [
            'data' => [
                'messages' => [
                    'key' => [
                        'id' => $messageId,
                    ],
                    'message' => [
                        $this->messageTypeKey => [
                            'url' => $url,
                            'mimetype' => $type,
                            'mediaKey' => $mediaKey,
                            'fileName' => uniqid() . '.' . self::fileExtensionFor($type),
                        ],
                    ],
                ],
            ],
        ];
        $messageService = new MessageService($this->message->app, $this->message->company);
        $response = $messageService->decryptMediaFile($payload);
        SafeUrl::assertSafe($response['publicUrl']);
        $content = Http::get($response['publicUrl']);
        // Generate a unique filename
        $filename = uniqid() . '.' . self::fileExtensionFor($type);

        // Save to temporary storage using Laravel's Storage
        $tempPath = 'temp/' . $filename;
        Storage::put($tempPath, $content->body());

        return [
            'content' => $content->body(),
            'path' => Storage::path($tempPath),
            'filename' => $filename,
        ];
    }

    /**
     * WhatsApp hands us a mimetype (`image/jpeg`), not a bare kind. This used to `match` on
     * 'image'/'video' and so fell through to `bin` for every real payload — the file landed named
     * `*.bin`, and WordPress rejects uploads by filename extension, so every published article
     * came out with no featured image.
     */
    public static function fileExtensionFor(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return self::EXTENSION_BY_MIME[$mimeType]
            ?? match (explode('/', $mimeType)[0]) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'ogg',
                default => 'bin',
            };
    }

    /**
     * Process WhatsApp media and add it to the message
     */
    protected function processWhatsAppMedia(
        string $messageId,
        string $mediaKey,
        string $url,
        string $type = 'image'
    ): Filesystem {
        // Get user from message
        $user = $this->message->user;

        // Decrypt the media
        $decryptedMedia = $this->decryptWhatsAppMedia($messageId, $mediaKey, $url, $type);

        // Use FilesystemServices to create a filesystem entity from the decrypted content
        $fileSystem = $this->filesystemService->createFileSystemFromBase64(
            base64_encode($decryptedMedia['content']), // Convert content to base64
            $decryptedMedia['filename'],
            $user
        );

        // Attach the file to the message (assuming Message model implements HasFilesystemTrait)
        $this->message->addFile($fileSystem, $type);

        // Clean up temporary file
        Storage::delete('temp/' . $decryptedMedia['filename']);

        return $fileSystem;
    }
}
