<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Kanvas\Filesystem\Actions\AttachFilesystemAction;

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
    public function attach(Filesystem|array $files) : bool
    {
        if (is_array($files)) {
            foreach ($files as $file) {
                $attachFilesystem = new AttachFilesystemAction($file, $this);
                $attachFilesystem->execute();
            }
        } else {
            $attachFilesystem = new AttachFilesystemAction($files, $this);
            $attachFilesystem->execute();
        }

        return true;
    }
}
