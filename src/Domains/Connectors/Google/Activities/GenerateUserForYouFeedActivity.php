<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Google\Actions\GenerateGoogleUserMessageAction;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class GenerateUserForYouFeedActivity extends KanvasActivities implements WorkflowActivityInterface
{
    public $tries = 10;

    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $companyBranchId = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $globalAppCompany = CompaniesBranches::where('id', $companyBranchId)->first();

        $company = $globalAppCompany ? $globalAppCompany->company : $user->getCurrentCompany();

        $cleanUserFeed = $params['cleanUserFeed'] ?? false;
        $pageSize = $params['pageSize'] ?? 350;
        $generateUserMessage = new GenerateGoogleUserMessageAction(
            $app,
            $company,
            $user,
            $cleanUserFeed
        );
        $generateUserMessage->execute($pageSize);

        return [
            'user_id' => $user->getId(),
            'app_id' => $app->getId(),
            'clean_user_feed' => $cleanUserFeed,
            'message' => 'User message feed generated',
        ];
    }
}
