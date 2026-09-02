<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;

/**
 * The row shape the list_*_files tools hand back.
 *
 * `filesystem_id` and `readable` lead because the useful next move is read_file: a listing carrying
 * URLs teaches the model to describe files instead of opening them. A link for a person to click is
 * a separate, asked-for call — get_file_link — precisely so it does not ride along on every listing.
 */
trait PresentsEntityFiles
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function presentFiles(EloquentModel $entity, string $source): array
    {
        $extractor = new FileTextExtractor();

        return $entity->files()
            ->orderBy('filesystem.id')
            ->get()
            ->map(fn (Filesystem $file): array => [
                'filesystem_id' => (int) $file->getId(),
                'file_name' => $file->name,
                'file_type' => $file->file_type,
                'size_bytes' => (int) $file->size,
                'source' => $source,
                'readable' => $extractor->supports($file),
            ])
            ->values()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    protected function fileListing(array $files, string $emptyNote): array
    {
        $readable = array_values(array_filter($files, static fn (array $f): bool => $f['readable'] === true));

        return [
            'files' => $files,
            'count' => count($files),
            'note' => $files === []
                ? $emptyNote
                : sprintf(
                    '%d file(s), %d readable. Call read_file with a filesystem_id to read one — never describe a '
                        . 'file you have not read. When you are handing a file to a person rather than reading it '
                        . 'yourself, call get_file_link so they get a link instead of an id.',
                    count($files),
                    count($readable),
                ),
        ];
    }
}
