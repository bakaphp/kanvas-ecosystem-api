<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Services\ImageOptimizerService;

class MessageFileConstrainerService
{
    /**
     * Constrain image file sizes for message types whose verb is in the app's
     * `filesystem-message-constrain-verbs` allowlist, using `filesystem-message-max-filesize`
     * as the per-file byte budget.
     *
     * Returns the (possibly mutated) files array — UploadedFile instances are constrained
     * in-place; HEIC inputs are converted to JPEG and re-wrapped in a new UploadedFile.
     * Non-UploadedFile entries are passed through unchanged.
     */
    public static function constrain(AppInterface $app, string $verb, array $files): array
    {
        $maxFileSize = (int) ($app->get('filesystem-message-max-filesize') ?: 0);
        if ($maxFileSize <= 0) {
            return $files;
        }

        $allowedVerbs = (array) $app->get('filesystem-message-constrain-verbs');
        if (empty($allowedVerbs) || ! in_array($verb, $allowedVerbs, true)) {
            return $files;
        }

        foreach ($files as $key => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $originalPath = $file->getRealPath();
            $convertedPath = ImageOptimizerService::constrainFileSize(
                $originalPath,
                $maxFileSize,
            );

            // constrainFileSize may convert HEIC to JPEG at a new path,
            // so we need to update the file reference
            if ($convertedPath !== $originalPath) {
                $files[$key] = new UploadedFile(
                    $convertedPath,
                    pathinfo($convertedPath, PATHINFO_BASENAME),
                    (string) mime_content_type($convertedPath),
                    $file->getError(),
                    true
                );
            }
        }

        return $files;
    }
}
