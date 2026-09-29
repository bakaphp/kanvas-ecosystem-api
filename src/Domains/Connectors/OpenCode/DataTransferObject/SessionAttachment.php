<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Spatie\LaravelData\Data;

/**
 * One file handed to a coding session beside the repository — a design, a mockup, a screenshot.
 *
 * Written under `.kanvas/attachments/`, which the provisioner already keeps out of git, so it can be read
 * but never lands in the diff or the pull request.
 */
class SessionAttachment extends Data
{
    public const string DIRECTORY = '.kanvas/attachments';

    public function __construct(
        public readonly int $filesystemId,
        public readonly string $relativePath,
        public readonly string $mimeType,
        public readonly string $bytes,
    ) {
    }

    /**
     * The extension comes from the bytes, never the uploaded name: whoever uploads picks the name, and a
     * name is how crafted bytes get steered into the wrong decoder. The id prefix keeps two uploads that
     * share a name apart, and the slug keeps the name from reaching outside the directory.
     */
    public static function fromFile(Filesystem $file, string $bytes): self
    {
        $mimeType = FilesystemServices::detectMimeTypeFromBytes($bytes);
        $stem = Str::trimToNull(Str::slug(pathinfo((string) $file->name, PATHINFO_FILENAME))) ?? 'file';

        return new self(
            filesystemId: $file->getId(),
            relativePath: self::DIRECTORY . '/' . $file->getId() . '-' . $stem . '.'
                . FilesystemServices::getExtensionFromMimeType($mimeType),
            mimeType: $mimeType,
            bytes: $bytes,
        );
    }
}
