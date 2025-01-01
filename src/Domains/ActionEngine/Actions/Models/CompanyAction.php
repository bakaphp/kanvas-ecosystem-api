<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Nevadskiy\Tree\AsTree;

/**
 * Class CompanyAction.
 *
 * @property int $id
 * @property string $uuid
 * @property int $actions_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $users_id
 * @property int $pipelines_id
 * @property int $parent_id
 * @property string $path
 * @property string $name
 * @property string $icon
 * @property string $description
 * @property string $form_config
 * @property int $is_active
 * @property int $is_published
 * @property int $weight
 */
class CompanyAction extends BaseModel
{
    use UuidTrait;
    use AsTree;

    protected $table = 'companies_actions';
    protected $guarded = [];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'actions_id', 'id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public static function getByAction(
        Action $action,
        CompanyInterface $company,
        AppInterface $app,
        ?CompaniesBranches $branch = null
    ): self {
        // Define the base query
        $query = self::query()
            ->where('actions_id', $action->getId())
            ->fromCompany($company)
            ->whereIn('apps_id', [$app->getId(), AppEnums::GLOBAL_APP_ID->getValue()])
            ->where('is_deleted', 0);

        // Add branch condition if provided
        if ($branch) {
            $query->where('companies_branches_id', $branch->getId());
        }

        // Try to fetch the first matching record
        $companyAction = $query->orderByDesc('id')->first();

        // If no result, fall back to the alternative condition
        if (! $companyAction) {
            $query = self::query()
                ->where('actions_id', $action->getId())
                ->whereIn('companies_id', [$company->getId(), AppEnums::GLOBAL_COMPANY_ID->getValue()])
                ->whereIn('apps_id', [$app->getId(),  AppEnums::GLOBAL_APP_ID->getValue()])
                ->where('is_deleted', 0);

            $companyAction = $query->orderByDesc('id')->firstOrFail();
        }

        return $companyAction;
    }
}
