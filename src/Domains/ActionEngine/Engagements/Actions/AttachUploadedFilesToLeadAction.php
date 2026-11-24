<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Engagements\Support\UploadedDocumentsPayload;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Attaches the files a submitted engagement uploaded (`data.{typeId}.files[]`) to the lead, so every
 * client (web, mobile, extension) gets them without each one calling attachFile.
 *
 * Every file is resolved and validated before anything is written. The writes span the `crm` and
 * `ecosystem` connections, which no single transaction can cover, so a failure here must happen
 * before the engagement is created and a retry must be safe: a file already on the lead is skipped.
 */
class AttachUploadedFilesToLeadAction
{
    /** filesystem_entities.field_name is varchar(50). */
    private const int FIELD_NAME_MAX_LENGTH = 50;

    /**
     * @param array<mixed> $data the `data` of the submitted engagement
     */
    public function __construct(
        protected Lead $lead,
        protected array $data,
    ) {
    }

    /**
     * @return Collection<int, FilesystemEntities> only the files attached by this call
     */
    public function execute(): Collection
    {
        $documents = $this->resolveDocuments();

        if ($documents === []) {
            return collect();
        }

        return DB::connection('crm')->transaction(function () use ($documents): Collection {
            // Two submits racing for the same lead would otherwise compute the same version number.
            Lead::query()->whereKey($this->lead->getKey())->lockForUpdate()->firstOrFail();

            return DB::connection('ecosystem')->transaction(
                fn (): Collection => $this->attach($documents)
            );
        });
    }

    /**
     * @return array<int, Filesystem> keyed by filesystem id
     */
    protected function resolveDocuments(): array
    {
        $documents = [];

        foreach (UploadedDocumentsPayload::files($this->data) as $file) {
            $filesystem = $file['uuid'] !== null
                ? Filesystem::getByUuidFromCompanyApp($file['uuid'], $this->lead->company, $this->lead->app)
                : Filesystem::getByIdFromCompanyApp($file['id'], $this->lead->company, $this->lead->app);

            if ((int) $filesystem->is_deleted === StateEnums::YES->getValue()) {
                throw new ValidationException(
                    'The file ' . $filesystem->uuid . ' was deleted before the submission'
                );
            }

            $documents[$filesystem->getId()] ??= $filesystem;
        }

        return $documents;
    }

    /**
     * @param array<int, Filesystem> $documents
     *
     * @return Collection<int, FilesystemEntities>
     */
    protected function attach(array $documents): Collection
    {
        $module = SystemModulesRepository::getByModelName(Lead::class, $this->lead->app);

        // Deleted rows count for names: a version number is never handed out twice.
        $existing = FilesystemEntities::query()
            ->where('entity_id', $this->lead->getKey())
            ->where('system_modules_id', $module->getKey())
            ->get(['filesystem_id', 'field_name', 'is_deleted']);

        $attachedIds = $existing
            ->where('is_deleted', StateEnums::NO->getValue())
            ->pluck('filesystem_id')
            ->all();
        $usedNames = $existing->pluck('field_name')
            ->map(fn (?string $name): string => mb_strtolower((string) $name))
            ->all();

        $attached = collect();

        foreach ($documents as $filesystemId => $filesystem) {
            if (in_array($filesystemId, $attachedIds, true)) {
                continue;
            }

            $fieldName = $this->uniqueFieldName($filesystem->name, $usedNames);
            $usedNames[] = mb_strtolower($fieldName);

            $attached->push(
                new AttachFilesystemAction($filesystem, $this->lead)->execute($fieldName)
            );
        }

        return $attached;
    }

    /**
     * The file name as it arrives, and only when the lead already has it `{stem} (2).{ext}`, `(3)`...
     * Without app-level duplicates the
     * attach action replaces the file of an existing field_name, so every attachment needs its own.
     * MySQL compares field_name case-insensitively, hence the lowercased `$usedNames`.
     *
     * @param array<int, string> $usedNames
     */
    protected function uniqueFieldName(string $fileName, array $usedNames): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $extension = $extension === '' ? '' : '.' . $extension;

        for ($version = 1; ; $version++) {
            $suffix = ($version > 1 ? ' (' . $version . ')' : '') . $extension;
            $room = max(1, self::FIELD_NAME_MAX_LENGTH - mb_strlen($suffix));
            $candidate = mb_substr($stem, 0, $room) . $suffix;

            if (! in_array(mb_strtolower($candidate), $usedNames, true)) {
                return $candidate;
            }
        }
    }
}
