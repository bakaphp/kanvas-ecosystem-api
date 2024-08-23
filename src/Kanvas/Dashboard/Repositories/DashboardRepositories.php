<?php
declare(strict_types=1);

namespace Kanvas\Dashboard\Repositories;

class DashboardRepositories
{
    public static function getDefaultFields(): array
    {
        return [
            'total_lead' => 0,
            'total_users' => 0,
            'total_people' => 0,
            'total_companies' => 0,
            'total_products' => 0,
            'total_categories' => 0,
        ];
    }
}
