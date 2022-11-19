<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Regions\Actions;

use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Illuminate\Support\Str;

class CreateRegionAction
{
    public function __construct(
        protected RegionDto $data,
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
            'companies_id' => $this->data->companies_id,
            'apps_id' => $this->data->apps_id,
            'currency_id' => $this->data->currency_id,
            'uuid' => Str::uuid(),
            'name' => $this->data->name,
            'slug' => $this->data->slug,
            'short_slug' => $this->data->short_slug,
            'settings' => $this->data->settings,
            'is_default' => $this->data->is_default,
        ]);
    }
}
