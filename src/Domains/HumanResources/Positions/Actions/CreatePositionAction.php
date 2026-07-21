<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Positions\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Positions\DataTransferObject\Position as PositionData;
use Kanvas\HumanResources\Positions\Models\Position;

class CreatePositionAction
{
    public function __construct(
        protected readonly PositionData $data,
    ) {
    }

    public function execute(): Position
    {
        return DB::connection('hr')->transaction(function () {
            $position = new Position();
            $position->apps_id = $this->data->app->getId();
            $position->companies_id = $this->data->company->getId();
            $position->users_id = $this->data->user->getId();
            $position->department_id = $this->data->department?->getId();
            $position->title = $this->data->title;
            $position->level = $this->data->level;
            $position->description = $this->data->description;
            $position->is_active = $this->data->isActive;
            $position->saveOrFail();

            return $position;
        });
    }
}
