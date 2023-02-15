<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Actions;

use Kanvas\SystemModules\DataTransferObject\SystemModulesData;
use Kanvas\SystemModules\Models\SystemModules;

class CreateSystemModulesAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected SystemModulesData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Apps
     */
    public function execute(): SystemModules
    {
        $systemModule = new SystemModules();
        $systemModule->name = $this->data->name;
        $systemModule->slug = $this->data->slug;
        $systemModule->model_name = $this->data->model_name;
        $systemModule->apps_id = $this->data->apps_id;
        $systemModule->parents_id = $this->data->parents_id;
        $systemModule->menu_order = $this->data->menu_order;
        $systemModule->show = $this->data->show;
        $systemModule->use_elastic = $this->data->use_elastic;
        $systemModule->browse_fields = $this->data->browse_fields;
        $systemModule->bulk_actions = $this->data->bulk_actions;
        $systemModule->mobile_component_type = $this->data->mobile_component_type;
        $systemModule->mobile_navigation_type = $this->data->mobile_navigation_type;
        $systemModule->mobile_tab_index = $this->data->mobile_tab_index;
        $systemModule->protected = $this->data->protected;
        $systemModule->save();

        return $systemModule;
    }
}
