<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Files a provider downloaded for an inbound message. The download is the provider's (each vendor
 * authenticates its own media); storing it is the same for all of them.
 */
final class StoreInboundFileAction
{
    /**
     * The media a customer can send over a messaging channel. Twilio's DownloadMessageFileAction
     * carries its own copy of this map until Twilio joins this domain.
     */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/3gpp' => '3gp',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/amr' => 'amr',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
    ];

    public function __construct(
        private readonly Message $message,
        private readonly string $contents,
        private readonly string $contentType,
        private readonly string $prefix
    ) {
    }

    public function execute(): Filesystem
    {
        // WhatsApp voice notes arrive as `audio/ogg; codecs=opus`.
        $type = strtolower(trim(explode(';', $this->contentType)[0]));
        $extension = self::EXTENSIONS[$type]
            ?? throw new InvalidArgumentException('Unsupported media type: ' . $this->contentType);

        $filename = uniqid($this->prefix . '_') . '.' . $extension;
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $this->contents);

        $user = $this->message->company->getAiAgentUser() ?? Users::getById($this->message->users_id);

        try {
            return new FilesystemServices($this->message->app, $this->message->company)->upload(
                new UploadedFile($tempPath, $filename, $type, null, true),
                $user
            );
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
