<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenCode\DataTransferObject\SessionAttachment;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Throwable;

/**
 * Turns the filesystem ids an agent names into files a coding session can open.
 *
 * Every failure throws rather than skipping the file: a session told "match the design" that silently
 * started without it answers from imagination, and nobody notices until the pull request.
 */
class SessionAttachmentService
{
    public const int MAX_FILES = 10;
    public const int MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * Scoped to the agent's own tenant: the ids come from the model, and a model can be talked into
     * naming another company's file.
     *
     * @param list<int> $ids
     * @return list<Filesystem>
     */
    public function resolve(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if (count($ids) > self::MAX_FILES) {
            throw new ValidationException('A coding task takes at most ' . self::MAX_FILES . ' attachments.');
        }

        if ($ids === []) {
            return [];
        }

        $files = Filesystem::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff($ids, $files->keys()->all()));

        if ($missing !== []) {
            throw new ValidationException(
                'Attachment(s) ' . implode(', ', $missing) . ' were not found in this company.'
            );
        }

        return array_map(static fn (int $id): Filesystem => $files[$id], $ids);
    }

    /**
     * @param list<Filesystem> $files
     * @return list<SessionAttachment>
     */
    public function load(array $files): array
    {
        return array_map(function (Filesystem $file): SessionAttachment {
            try {
                $bytes = FilesystemServices::readBytes($file);
            } catch (Throwable $e) {
                throw new ValidationException(
                    'Attachment ' . $file->getId() . ' could not be downloaded: ' . $e->getMessage()
                );
            }

            if ($bytes === '') {
                throw new ValidationException('Attachment ' . $file->getId() . ' is empty.');
            }

            if (strlen($bytes) > self::MAX_BYTES) {
                throw new ValidationException(
                    'Attachment ' . $file->getId() . ' is larger than ' . (self::MAX_BYTES / 1024 / 1024) . 'MB.'
                );
            }

            return SessionAttachment::fromFile($file, $bytes);
        }, $files);
    }

    /**
     * @param list<SessionAttachment> $attachments
     */
    public static function promptBlock(array $attachments): ?string
    {
        if ($attachments === []) {
            return null;
        }

        $lines = array_map(
            static fn (SessionAttachment $attachment): string => sprintf(
                '- %s (%s)',
                $attachment->relativePath,
                $attachment->mimeType
            ),
            $attachments,
        );

        return 'ATTACHED FILES:' . "\n"
            . implode("\n", $lines) . "\n"
            . 'They are in your working directory but not part of the repository. Open every one of them '
            . 'before you start — a design or screenshot is what the result has to match. Leave them where '
            . 'they are; if one is an asset the work itself needs, such as a logo or an icon, copy it into '
            . 'the right place in the repository.';
    }
}
