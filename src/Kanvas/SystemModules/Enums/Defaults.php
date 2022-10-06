<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Enums;

use Kanvas\Companies\Models\Companies;
use Kanvas\Contracts\EnumsInterface;
use Kanvas\Roles\Models\Roles;
use Kanvas\Users\Models\Users;

enum Defaults implements EnumsInterface
{
    case COMPANIES;
    case USERS;
    case ROLES;

    public function getValue() : mixed
    {
        return match ($this) {
            self::COMPANIES => [
                'name' => 'Companies',
                'slug' => 'companies',
                'model_name' => Companies::class,
                'parents_id' => '0',
                'menu_order' => '0',
                'show' => '1',
                'use_elastic' => '0',
                'browse_fields' => '[{"name":"name","title":"Name","sortField":"name","filterable":true,"searchable":true},{"name":"address","title":"Address","sortField":"address","filterable":true,"searchable":true},{"name":"timezone","title":"Timezone","sortField":"timezone","filterable":true,"searchable":true},{"name":"website","title":"Website","sortField":"website","filterable":true,"searchable":true}]',
                'bulk_actions' => null,
                'mobile_component_type' => null,
                'mobile_navigation_type' => null,
                'mobile_tab_index' => '0',
                'protected' => '0'
            ],
            self::USERS => [
                'name' => 'Users',
                'slug' => 'users',
                'model_name' => Users::class,
                'parents_id' => '0',
                'menu_order' => '0',
                'show' => '1',
                'use_elastic' => '0',
                'browse_fields' => '[{"name":"firstname","title":"First Name","sortField":"firstname","filterable":true,"searchable":true},{"name":"lastname","title":"Last Name","sortField":"lastname","filterable":true,"searchable":true},{"name":"email","title":"Email","sortField":"email","filterable":true,"searchable":true},{"name":"displayname","title":"Display Name","sortField":"displayname","filterable":true,"searchable":true}]',
                'bulk_actions' => null,
                'mobile_component_type' => null,
                'mobile_navigation_type' => null,
                'mobile_tab_index' => '0',
                'protected' => '0'
            ],
            self::ROLES => [
                'name' => 'Roles',
                'slug' => 'roles',
                'model_name' => Roles::class,
                'parents_id' => '0',
                'menu_order' => '0',
                'show' => '0',
                'use_elastic' => '0',
                'browse_fields' => '[{"name":"name","title":"Name","sortField":"name","filterable":true,"searchable":true},{"name":"description","title":"Description"}]',
                'bulk_actions' => null,
                'mobile_component_type' => null,
                'mobile_navigation_type' => null,
                'mobile_tab_index' => '0',
                'protected' => '0'
            ],
        };
    }
}
