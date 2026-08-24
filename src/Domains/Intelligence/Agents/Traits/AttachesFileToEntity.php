<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use InvalidArgumentException;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Throwable;

/**
 * Shared body of the upload_file_to_* tools, on both the Neuron and the Laravel-AI side. Host needs
 * either framework's HasKanvasContext — both expose $app, $company and contextUser(). The file is
 * created under the tool's own app + company, so the host must resolve the entity tenant-scoped first.
 *
 * The attachment's field_name is the file name rather than a fixed slot: with
 * FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME off (the default) AttachFilesystemAction replaces the
 * existing file sharing a field_name, so a constant slot would make every upload overwrite the last.
 */
trait AttachesFileToEntity
{
    /** Narrower than the upload allow-list: inline content is a model-authored string, so only text. */
    private const CONTENT_EXTENSIONS = [
        'md',
        'txt',
        'csv',
        'json',
        'html',
        'xml',
        'yml',
        'yaml',
    ];

    private const MAX_CONTENT_BYTES = 262144;

    /**
     * @return array<string, mixed>
     */
    protected function attachFileToEntity(
        EloquentModel $entity,
        string $entityLabel,
        ?string $fileUrl = null,
        ?string $content = null,
        ?string $fileName = null,
    ): array {
        $fileUrl = trim((string) $fileUrl) ?: null;
        $content = trim((string) $content) ?: null;
        $fileName = trim((string) $fileName) ?: null;

        if ($fileUrl === null && $content === null) {
            return [
                'status' => 'error',
                'message' => 'Nothing to upload. Pass `content` with the text of the document you want stored '
                    . '(the usual case — write the markdown yourself), or `file_url` with a public URL to '
                    . 'download. Retry with one of them.',
            ];
        }

        if ($fileUrl !== null && $content !== null) {
            return [
                'status' => 'error',
                'message' => 'Pass either `content` or `file_url`, not both. Retry with just one.',
            ];
        }

        $user = $this->contextUser();

        if ($user === null) {
            return [
                'status' => 'error',
                'message' => 'This conversation has no Kanvas user to attribute the upload to, so the file cannot '
                    . 'be stored. Do not retry — tell the person the upload failed.',
            ];
        }

        if ($content !== null && strlen($content) > self::MAX_CONTENT_BYTES) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'The content is %d bytes, over the %d byte limit. Split it into several smaller documents and '
                        . 'upload them one at a time.',
                    strlen($content),
                    self::MAX_CONTENT_BYTES,
                ),
            ];
        }

        $allowed = $content !== null
            ? self::CONTENT_EXTENSIONS
            : AllowedFileExtensionEnum::WORK_FILES->getAllowedExtensions();

        $default = $content !== null
            ? 'agent-document.md'
            : basename((string) parse_url($fileUrl, PHP_URL_PATH));

        $name = $this->safeFileName($fileName ?? $default);

        if ($name === null || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowed, true)) {
            return [
                'status' => 'error',
                'message' => 'file_name must be a plain file name with an extension, one of: '
                    . implode(', ', $allowed) . '. Retry with a valid file_name.',
            ];
        }

        try {
            $filesystem = new FilesystemServices($this->app, $this->company);

            $file = $content !== null
                ? $filesystem->createFileSystemFromBase64(base64_encode($content), $name, $user)
                : $filesystem->uploadFileFromUrl($fileUrl, $user);
        } catch (InvalidArgumentException) {
            return [
                'status' => 'error',
                'message' => 'The file at that URL could not be downloaded — it may be private, expired or blocked. '
                    . 'Do not retry the same URL; ask the person for a public link, or write the document with '
                    . '`content` instead.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The file could not be stored. Do not claim it was uploaded — tell the person it failed.',
            ];
        }

        try {
            $entity->addFile($file, $name);
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => sprintf(
                    'The file was stored but could not be attached to the %s. Do not claim it was uploaded — tell '
                        . 'the person it failed.',
                    $entityLabel,
                ),
            ];
        }

        return [
            'status' => 'success',
            'filesystem_id' => (int) $file->getId(),
            'file_name' => $file->name,
            'file_url' => $file->url,
            'message' => sprintf(
                'File "%s" is attached to the %s and visible to the team under its Files.',
                $file->name,
                $entityLabel,
            ),
        ];
    }

    /**
     * The name reaches FilesystemServices as a temp-file path segment and lands in object storage,
     * so a model-supplied `../../etc/x` must never survive. Null when nothing usable is left.
     */
    private function safeFileName(string $candidate): ?string
    {
        $name = basename(str_replace('\\', '/', $candidate));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $name), '-.');

        if ($name === '' || pathinfo($name, PATHINFO_EXTENSION) === '') {
            return null;
        }

        return $name;
    }
}
