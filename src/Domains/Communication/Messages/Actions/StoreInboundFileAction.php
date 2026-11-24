<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
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
    /** The media a customer can send over a messaging channel. */
    private const array SUPPORTED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'video/mp4',
        'video/quicktime',
        'video/3gpp',
        'audio/mpeg',
        'audio/ogg',
        'audio/amr',
        'audio/aac',
        'audio/mp4',
        'audio/wav',
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
        $type = MediaTypeEnum::canonicalMime($this->contentType);

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported media type: ' . $this->contentType);
        }

        $filename = uniqid($this->prefix . '_') . '.' . MediaTypeEnum::extensionForMime($type);
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $this->contents);

        try {
            $user = $this->message->company->getAiAgentUser() ?? Users::getById($this->message->users_id);

            return new FilesystemServices($this->message->app, $this->message->company)->upload(
                new UploadedFile(
                    $tempPath,
                    $filename,
                    $type,
                    test: true
                ),
                $user
            );
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
