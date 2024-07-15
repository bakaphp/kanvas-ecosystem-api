<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Enums\AppEnums;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;

class RuleRepository
{
    public static function getRulesByModelAndType(
        AppInterface $app,
        EloquentModel $model,
        RuleType $ruleType,
        ?CompanyInterface $company = null
    ): Collection {
        $systemModule = SystemModulesRepository::getByModelName(get_class($model), $app);
        $bind = [
            'systems_module_id' => $systemModule->getId(),
            'rules_types_id' => $ruleType->getId(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'global_companies' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'global_apps_id' => AppEnums::ECOSYSTEM_APP_ID->getValue(),
            'apps_id' => $app->getId(),
        ];

        //if it has a company reference m
        $companyId = null;

        /**
         * calling company will override the model default connection we do this to avoid issues in the queue
         * @todo look for a better way to do this.
         */
        $duplicateModel = clone $model;

        if ($company) {
            $companyId = $company->getId();
        } elseif (isset($duplicateModel->company) && $duplicateModel->company instanceof CompanyInterface) {
            $companyId = $duplicateModel->company->getId();
        }

        if ($companyId !== null) {
            $bind['companies_id'] = $companyId;
        }
        return Rule::where('systems_modules_id', $bind['systems_module_id'])
                ->where('rules_types_id', $bind['rules_types_id'])
                ->whereIn('companies_id', [$bind['companies_id'], $bind['global_companies']])
                ->whereIn('apps_id', [$bind['global_apps_id'], $bind['apps_id']])
                ->where('is_deleted', 0)
                ->notDeleted()
                ->get();
    }
}
