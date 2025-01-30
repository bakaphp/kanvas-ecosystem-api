<?php
declare(strict_types=1);

namespace Kanvas\Guild\Rotations\Actions;

use Kanvas\Guild\Rotations\DataTransferObject\Rotation as RotationDto;
use Kanvas\Guild\Rotations\Models\Rotation as RotationModel;

class UpdateRotationAction
{
    public function __construct(
        protected RotationDto $data
    ) {
    }

    public function execute(): RotationModel
    {
        $rotation = RotationModel::getById(
            $this->data->id,
            $this->data->company->app
        );

        $rotation->update([
            'name' => $this->data->name,
            'companies_id' => $this->data->company->getId(),
            'users_id' => $this->data->user->getId(),
        ]);
        $rotation->users()->syncWithoutDetaching($this->data->users);
        return $rotation;
    }
}
