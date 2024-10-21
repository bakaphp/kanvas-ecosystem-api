<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class GenerateCompanyDashboardActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 5;

    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if ($entity instanceof CompanyInterface) {
            $company = $entity;
        } elseif (is_object($entity->company)) {
            $company = $entity->company;
        } else {
            return [
                'msg' => 'No company found for entity',
                'entity_class' => get_class($entity),
                'entity' => $entity->toArray(),
            ];
        }

        $userAssociatedCompanyBuilder = UsersAssociatedApps::where('apps_id', $app->getId())
                    ->where('is_deleted', StateEnums::NO->getValue())
                    ->where('companies_id', $company->getId());

        $totalUsers = (clone $userAssociatedCompanyBuilder)->count();
        $totalActiveUsers = (clone $userAssociatedCompanyBuilder)->where('is_active', 1)->count();
        $suspendedUsers = (clone $userAssociatedCompanyBuilder)->where('is_active', 0)->count();

        $totalProducts = Products::fromApp($app)->fromCompany($company)->notDeleted()->where('is_published', 1)->count();
        $totalUnpublishedProducts = Products::fromApp($app)->fromCompany($company)->notDeleted()->where('is_published', 0)->count();

        $dashboard = [
            'total_users' => $totalUsers,
            'total_active_users' => $totalActiveUsers,
            'total_suspended_users' => $suspendedUsers,
            'total_products' => $totalProducts,
            'total_expired_products' => $totalUnpublishedProducts,
        ];

        $company->set('dashboard', $dashboard, true);

        return array_merge(['company' => $company->getId()], $dashboard);
    }
}
