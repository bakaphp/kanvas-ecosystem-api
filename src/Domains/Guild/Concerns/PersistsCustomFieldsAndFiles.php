<?php

declare(strict_types=1);

namespace Kanvas\Guild\Concerns;

use Illuminate\Database\Eloquent\Model;

trait PersistsCustomFieldsAndFiles
{
    protected function persistCustomFieldsAndFiles(Model $entity, array $customFields, array $files): void
    {
        if ($customFields !== []) {
            $entity->setCustomFields($customFields);
            $entity->saveCustomFields();
        }

        if ($files !== []) {
            $entity->addMultipleFilesFromUrl($files);
        }
    }
}
