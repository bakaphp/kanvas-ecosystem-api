<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Regions\Actions;

use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Illuminate\Support\Str;

class CreateRegionAction
{
    public function __construct(
        protected RegionDto $dto,
    ) {
    }

    /**
     * execute
     *
     * @return RegionModel
     */
    public function execute(): RegionModel
    {
        return RegionModel::create([
            'companies_id' => $this->dto->companies_id,
            'apps_id' => $this->dto->apps_id,
            'currency_id' => $this->dto->currency_id,
            'uuid' => Str::uuid(),
            'name' => $this->dto->name,
            'slug' => $this->dto->slug,
            'short_slug' => $this->dto->short_slug,
            'settings' => $this->dto->settings,
            'is_default' => $this->dto->is_default,
        ]);
    }
}
