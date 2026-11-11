<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * A sync `pull` rule on leads for the current app, wired to one activity. `pull` because nothing
 * else listens on it for leads, so a test measures its own rule and not every created rule in the
 * database.
 */
trait BuildsSyncPullRules
{
    use BuildsSyncRules;

    protected function createSyncPullRule(
        Apps $app,
        string $activityClass,
        string $actionName,
        array $params = []
    ): Rule {
        return $this->createSyncRule(
            app: $app,
            event: WorkflowEnum::PULL->value,
            modelClass: Lead::class,
            activityClass: $activityClass,
            params: $params,
            actionName: $actionName,
        );
    }

    protected function createLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
