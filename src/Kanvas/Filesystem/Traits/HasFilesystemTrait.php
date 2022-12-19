<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use RuntimeException;

trait HasFilesystemTrait
{
    /**
     * attach a file system or multiple to this entity.
     *
     * @param Filesystem|array $files
     *
     * @throws Exception
     *
     * @return bool
     */
    public function attach(Filesystem $files, string $fieldName) : bool
    {
        $attachFilesystem = new AttachFilesystemAction($files, $this);
        $attachFilesystem->execute($fieldName);

        return true;
    }

    /**
     * Attach multiple files.
     *
     * @param array $files<file: UploadedFile, fieldName: string>
     *
     * @throws RuntimeException
     *
     * @return bool
     */
    public function attachMultiple(array $files) : bool
    {
        foreach ($files as $file) {
            if (!isset($file['file']) || !isset($file['fieldName'])) {
                throw new RuntimeException('Missing file || fieldName index');
            }

            $attachFilesystem = new AttachFilesystemAction($file['file'], $this);
            $attachFilesystem->execute($file['fieldName']);
        }

        return true;
    }

    /**
     * Get list of files attached to this model
     *
     * @return Collection
     */
    public function getFiles() : Collection
    {
        $systemModule = SystemModulesRepository::getByModelName(self::class);

        return DB::table('filesystem')
                    ->join('filesystem_entities', 'filesystem.id', '=', 'filesystem_entities.filesystem_id')
                    ->where('filesystem_entities.entity_id', '=', $this->getKey())
                    ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
                    ->get();
    }
}
