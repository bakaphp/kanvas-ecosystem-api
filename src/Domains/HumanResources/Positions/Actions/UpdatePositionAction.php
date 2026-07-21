<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Positions\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Positions\DataTransferObject\Position as PositionData;
use Kanvas\HumanResources\Positions\Models\Position;

class UpdatePositionAction
{
    public function __construct(
        protected readonly Position $position,
        protected readonly PositionData $data,
    ) {
    }

    public function execute(): Position
    {
        return DB::connection('hr')->transaction(function () {
            $this->position->department_id = $this->data->department?->getId();
            $this->position->title = $this->data->title;
            $this->position->level = $this->data->level;
            $this->position->description = $this->data->description;
            $this->position->is_active = $this->data->isActive;
            $this->position->saveOrFail();

            return $this->position;
        });
    }
}
