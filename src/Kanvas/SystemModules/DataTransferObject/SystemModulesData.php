<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class SystemModulesData extends Data
{
    /**
     * Construct function.
     *
     * @property string $name
     * @property string $slug
     * @property string $model_name
     * @property int $apps_id
     * @property int $parents_id
     * @property int $menu_order
     * @property int $show
     * @property int $use_elastic
     * @property string $browse_fields
     * @property string $bulk_actions
     * @property string $mobile_component_type
     * @property string $mobile_navigation_type
     * @property int $mobile_tab_index
     * @property int $protected
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $model_name,
        public int $apps_id,
        public ?int $parents_id,
        public ?int $menu_order,
        public ?int $show,
        public ?int $use_elastic,
        public ?string $browse_fields,
        public ?string $bulk_actions,
        public ?string $mobile_component_type,
        public ?string $mobile_navigation_type,
        public ?int $mobile_tab_index,
        public int $protected,
    ) {
    }

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Settings data array
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            slug: $data['slug'],
            model_name: $data['model_name'],
            apps_id: (int)$data['apps_id'],
            parents_id: (int)$data['parents_id'],
            menu_order: (int)$data['menu_order'],
            show: (int)$data['show'],
            use_elastic: (int)$data['use_elastic'],
            browse_fields: $data['browse_fields'],
            bulk_actions: $data['bulk_actions'],
            mobile_component_type: $data['mobile_component_type'],
            mobile_navigation_type: $data['mobile_navigation_type'],
            mobile_tab_index: (int)$data['mobile_tab_index'],
            protected: (int)$data['protected'],
        );
    }
}
