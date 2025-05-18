<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class DownloadMessageFileAction
{
    protected FilesystemServices $filesystemService;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent
    ) {
        if (! MessageTypeEnum::isDocumentType($this->message->messageType->verb)) {
            throw new Exception('Invalid message type');
        }

        $this->filesystemService = new FilesystemServices(
            $this->message->app,
            $this->message->company ?? null
        );
    }

    public function execute(array $params = []): Filesystem
    {
        /*  $mediaKey = 'SBqPa+ZHCoVLDdbSve+7sUbh+YDHyf+XoRuuvxdXj48=';
         $url = 'https://mmg.whatsapp.net/o1/v/t62.7118-24/f2/m231/AQMBvwf5EiyXOdUQvtUDTcmS4ke_uYG1VJplhYBV8CejeAVhezKVUmB-cjS8kSl69SH0DeZss_i1c9h3ft18D5v7WncL7VF1BXO3zFwjGQ?ccb=9-4&oh=01_Q5Aa1gF5H2Hatef7zk77Pi86h_nQGkPchoxSCFh_amWPEp7vvg&oe=684B23A9&_nc_sid=e6ed6c&mms3=true';

         decryptWhatsAppMedia($mediaKey, $url, DIR . '/decrypted_image.jpg');  */
        $mediaKey = $this->message->message['raw_data']['message']['imageMessage']['mediaKey'] ?? null;
        $url = $this->message->message['raw_data']['message']['imageMessage']['url'] ?? null;
        $imageName = $this->message->message['message_id'] ?? null;

        return $this->processWhatsAppMedia($mediaKey, $url, 'image');
    }

    protected function base64url_decode($data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    protected function downloadFile($url): string
    {
        // Use Laravel's HTTP client instead of native PHP function
        return Http::withOptions([
            'verify' => false,
        ])->get($url)->body();
    }

    protected function getDecryptionKeys(string $mediaKey, string $type = 'image', int $length = 112): string
    {
        $info = match ($type) {
            'image' => 'WhatsApp Image Keys',
            'video' => 'WhatsApp Video Keys',
            'audio' => 'WhatsApp Audio Keys',
            'document' => 'WhatsApp Document Keys',
            default => throw new Exception('Invalid media type'),
        };

        return hash_hkdf('sha256', base64_decode($mediaKey), $length, $info, '');
    }

    protected function decryptWhatsAppMedia(string $mediaKey, string $url, string $type = 'image'): array
    {
        // Download encrypted file
        $encFile = $this->downloadFile($url);
        if (! $encFile) {
            throw new Exception('Failed to download file');
        }

        // Get decryption keys
        $keys = $this->getDecryptionKeys($mediaKey, $type);
        $iv = substr($keys, 0, 16);
        $cipherKey = substr($keys, 16, 32);

        // Extract ciphertext
        $ciphertext = substr($encFile, 0, strlen($encFile) - 10); // Remove last 10 bytes (MAC)

        // Decrypt the file
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
        if (! $plaintext) {
            throw new Exception('Failed to decrypt media');
        }

        // Generate a unique filename
        $filename = uniqid() . '.' . $this->getFileExtension($type);

        // Save to temporary storage using Laravel's Storage
        $tempPath = 'temp/' . $filename;
        Storage::put($tempPath, $plaintext);

        return [
            'content' => $plaintext,
            'path' => Storage::path($tempPath),
            'filename' => $filename,
        ];
    }

    protected function getFileExtension(string $type): string
    {
        return match ($type) {
            'image' => 'jpg',
            'video' => 'mp4',
            'audio' => 'ogg',
            'document' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * Process WhatsApp media and add it to the message
     */
    protected function processWhatsAppMedia(string $mediaKey, string $url, string $type = 'image'): Filesystem
    {
        // Get user from message
        $user = $this->message->user;

        // Decrypt the media
        $decryptedMedia = $this->decryptWhatsAppMedia($mediaKey, $url, $type);

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
