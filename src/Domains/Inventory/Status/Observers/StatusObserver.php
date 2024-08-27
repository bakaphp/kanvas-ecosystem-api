<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Status\Models\Status;

class StatusObserver
{
    public function creating(Status $status): void
    {
        $defaultStatus = $status::getDefault($status->company);

        // if default already exist remove its default
        if ($status->is_default && $defaultStatus) {
            $defaultStatus->is_default = false;
            $defaultStatus->saveQuietly();
        }

        if (! $status->is_default && ! $defaultStatus) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Status');
        }
    }

    public function updating(Status $status): void
    {
        $defaultStatus = Status::getDefault($status->company);

        // if default already exist remove its default
        if ($defaultStatus &&
            $status->is_default &&
            $status->getId() != $defaultStatus->getId()
        ) {
            $defaultStatus->is_default = false;
            $defaultStatus->saveQuietly();
        } elseif ($defaultStatus &&
            ! $status->is_default &&
            $status->getId() == $defaultStatus->getId()
        ) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Status');
        }
    }

    public function deleting(Status $status): void
    {
        if ($status->hasDependencies()) {
            throw new ValidationException('Can\'t delete, Status has products associated');
        }

        $defaultStatus = $status::getDefault($status->company);

        if ($defaultStatus->getId() == $status->getId()) {
            throw new ValidationException('Can\'t delete, you have to have at least one default Status');
        }
    }
}
