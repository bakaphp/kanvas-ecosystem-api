<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Concerns;

use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;

trait SyncsAttachedPhotos
{
    /**
     * Attaching through addFileFromUrl() records the URL only, but the link row fires its own
     * `created` workflow (and, on an EventVersion, a Lighthouse cache clear), so a photo is attached
     * only when its media id is new for that field, never on every re-sync.
     *
     * A changed or removed photo has only the link to the url the mirror attached soft-deleted, so a
     * photo someone uploaded in Kanvas under the same field name survives. What was attached is
     * recorded even when a later attach throws, so a retry does not attach the same photo twice.
     *
     * Kanvas wins a field: with FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME off (the default),
     * AttachFilesystemAction reuses the field's live link and repoints it, so attaching over a photo
     * uploaded in Kanvas would replace it. Such a field is left alone and not recorded, and the
     * mirror's photo is attached once the Kanvas one is gone. The field name stays `photo`: the site
     * reads `files` without filtering on it, and renaming would orphan every photo already attached.
     *
     * @param  array<string, array{media_id: int, url: string}>  $files  field name => photo
     */
    protected function syncPhotos(BaseModel $entity, array $files): void
    {
        $attached = $this->attachedPhotos($entity);

        $mediaIds = fn (array $photos) => array_map(fn (array $photo) => $photo['media_id'], $photos);

        if ($mediaIds($attached) == $mediaIds($files)) {
            return;
        }

        if (method_exists($entity, 'disableWorkflows')) {
            $entity->disableWorkflows();
        }

        // Legacy entries recorded no url, so their live link cannot be told from a Kanvas upload;
        // their field is taken over as before.
        $legacyFields = [];

        try {
            foreach ($attached as $field => $photo) {
                if (($files[$field]['media_id'] ?? null) !== $photo['media_id']) {
                    $this->detachPhoto($entity, (string) $field, $photo['url']);

                    if ($photo['url'] === null) {
                        $legacyFields[$field] = true;
                    }

                    unset($attached[$field]);
                }
            }

            foreach ($files as $field => $file) {
                if (isset($attached[$field])) {
                    continue;
                }

                if (! isset($legacyFields[$field]) && $this->hasKanvasPhoto($entity, (string) $field, $file['url'])) {
                    continue;
                }

                $entity->addFileFromUrl($file['url'], (string) $field, $entity->app);
                $attached[$field] = ['media_id' => $file['media_id'], 'url' => $file['url']];
            }
        } finally {
            $this->rememberPhotos($entity, $attached);
        }
    }

    /**
     * An entry written before urls were recorded holds only the media id; its url is unknown, so it
     * is never detached by url — a replacement still takes over the field's link.
     *
     * @return array<string, array{media_id: int, url: string|null}>
     */
    protected function attachedPhotos(BaseModel $entity): array
    {
        $stored = $entity->get(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value);

        if (! is_array($stored)) {
            return [];
        }

        $attached = [];

        foreach ($stored as $field => $photo) {
            $attached[(string) $field] = is_array($photo)
                ? ['media_id' => (int) ($photo['media_id'] ?? 0), 'url' => $photo['url'] ?? null]
                : ['media_id' => (int) $photo, 'url' => null];
        }

        return $attached;
    }

    /**
     * The mirror's own previous link is already detached by the time this runs, so any other live
     * link under the field is one Kanvas made.
     */
    protected function hasKanvasPhoto(BaseModel $entity, string $field, string $url): bool
    {
        return FilesystemEntitiesRepository::getFileFromEntityByNameBuilder($entity, $field)
            ->where('filesystem.url', '!=', $url)
            ->exists();
    }

    protected function detachPhoto(BaseModel $entity, string $field, ?string $url): void
    {
        if ($url === null) {
            return;
        }

        FilesystemEntitiesRepository::getFileFromEntityByNameBuilder($entity, $field)
            ->where('filesystem.url', $url)
            ->get()
            ->each(fn (FilesystemEntities $link) => $link->softDelete());
    }

    /**
     * @param  array<string, array{media_id: int, url: string|null}>  $attached
     */
    protected function rememberPhotos(BaseModel $entity, array $attached): void
    {
        $key = CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value;

        if ($attached === []) {
            $entity->del($key);

            return;
        }

        $entity->set($key, $attached);
    }
}
