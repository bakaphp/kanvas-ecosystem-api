<?php

declare(strict_types=1);

namespace Kanvas\Guild\Rotations\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Guild\Rotations\Models\Rotation;
use Kanvas\Guild\Rotations\Models\RotationUser;
use Kanvas\Guild\Rotations\DataTransferObject\RotationUser as RotationUserDto;

class AddUserToRotationAction
{
    public function __construct(
        protected RotationUserDto $data,
    ) {
    }

    public function execute(): RotationUser
    {
        return RotationUser::firstOrCreate([
            'companies_id' => $this->data->rotation->companies_id,
            'users_id' => $this->data->user->getId(),
            'rotations_id' => $this->data->rotation->getId(),
            'hits' => $this->data->hits,
            'name' => $this->data->name,
            'email' => $this->data->email,
            'percentage' => $this->data->percentage,
        ]);
    }
}
