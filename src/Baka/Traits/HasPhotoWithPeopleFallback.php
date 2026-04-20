<?php

declare(strict_types=1);

namespace Baka\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;

/**
 * Resolves an entity's display photo in a consistent order for any model that
 * has a `people` relation and uses `HasFilesystemTrait`.
 *
 * Resolution order:
 *   1. The entity's own file attached with `field_name = 'photo'`.
 *   2. The linked People's photo (`$this->people->getPhoto()`).
 *   3. The app's `DEFAULT_USER_AVATAR` setting.
 *   4. null.
 */
trait HasPhotoWithPeopleFallback
{
    public function getPhoto(): ?FilesystemEntities
    {
        $ownPhoto = $this->getFileByName('photo');
        if ($ownPhoto !== null) {
            return $ownPhoto;
        }

        if ($this->people !== null && method_exists($this->people, 'getPhoto')) {
            $peoplePhoto = $this->people->getPhoto();
            if ($peoplePhoto !== null) {
                return $peoplePhoto;
            }
        }

        $defaultAvatarId = app(Apps::class)->get(AppSettingsEnums::DEFAULT_USER_AVATAR->getValue());

        return $defaultAvatarId
            ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId)
            : null;
    }
}
