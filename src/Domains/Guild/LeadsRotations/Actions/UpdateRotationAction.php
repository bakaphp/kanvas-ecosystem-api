<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadsRotations\Actions;

use Kanvas\Guild\LeadsRotations\DataTransferObject\LeadRotation as RotationDto;
use Kanvas\Guild\LeadsRotations\Models\LeadRotation as LeadRotationModel;

class UpdateRotationAction
{
    public function __construct(
        protected RotationDto $data
    ) {
    }

    public function execute(): LeadRotationModel
    {

        $rotation = LeadRotationModel::where('id', $this->data->id)
                    ->where('users_id', $this->data->user->getId())
                    ->where('companies_id', $this->data->company->getId())
                    ->firstOrFail();
    
        $rotation->update([
            'name' => $this->data->name,
            'companies_id' => $this->data->company->getId(),
            'users_id' => $this->data->user->getId(),
        ]);
        $rotation->users()->syncWithoutDetaching($this->data->users);
        return $rotation;
    }
}
