<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadsRotations\Actions;

use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Guild\LeadsRotations\Models\LeadRotation;
use Kanvas\Guild\LeadsRotations\Models\LeadRotationUser;
use Kanvas\Guild\LeadsRotations\DataTransferObject\LeadRotationUser as RotationUserDto;

class AddUserToLeadRotationAction
{
    public function __construct(
        protected RotationUserDto $data,
    ) {
    }

    public function execute(): LeadRotationUser
    {
        return LeadRotationUser::firstOrCreate([
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
