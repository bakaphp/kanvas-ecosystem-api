<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadsRotations\Actions;

use Kanvas\Guild\LeadsRotations\DataTransferObject\LeadRotation as RotationDto;
use Kanvas\Guild\LeadsRotations\Models\LeadRotation as RotationModel;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Guild\LeadsRotations\Actions\AddUserToLeadRotationAction;
use Kanvas\Guild\LeadsRotations\DataTransferObject\LeadRotationUser;
use Kanvas\Apps\Models\Apps;

class CreateRotationAction
{
    public function __construct(
        protected RotationDto $data
    ) {
    }

    /**
     * execute.
     *
     * @return RotationModel
     */
    public function execute(): RotationModel
    {
        $rotation = RotationModel::firstOrCreate([
            'name' => $this->data->name,
            'companies_id' => $this->data->company->getId(),
            'users_id' => $this->data->user->getId(),
        ], [
            'users_id' => $this->data->user->getId(),
        ]);

        foreach ($this->data->users as $user) {
            $rotationUserDto = LeadRotationUser::from([
                'rotation' => $rotation,
                'user' => UsersRepository::getUserOfAppById((int) $user['user_id'], app(Apps::class)),
                'name' => key_exists('name', $user) ? $user['name'] : null,
                'email' => key_exists('email', $user) ? $user['email'] : null,
                'phone' => key_exists('phone', $user) ? $user['phone'] : null,
                'hits' => key_exists('hits', $user) ? $user['hits'] : 0,
                'percentage' => key_exists('percentage', $user) ? $user['percentage'] : null
            ]);
            (new AddUserToLeadRotationAction($rotationUserDto))->execute();
        }
        return $rotation;
    }
}
