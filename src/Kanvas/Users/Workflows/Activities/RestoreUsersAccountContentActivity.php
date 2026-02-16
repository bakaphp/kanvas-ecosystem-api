<?php

declare(strict_types=1);

namespace Kanvas\Users\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Actions\RestoreReactivatedAccountContentAction;
use Kanvas\Users\Models\RequestDeletedAccount;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class RestoreUsersAccountContentActivity extends KanvasActivity
{
    public function execute(Users $user, Apps $app, array $param): array
    {
        $this->overwriteAppService($app);

        // Search if the user is on the account deletion request table and remove it
        $requestDeletedAccount = RequestDeletedAccount::fromApp($app)->where('users_id', $user->getId())->where('is_deleted', 0)->first();
        if (! $requestDeletedAccount) {
            return [
                'message' => 'No account restoration needed',
                'result' => false,
                'user_id' => $user->getId(),
                'app_id' => $app->getId(),
            ];
        }
        $requestDeletedAccount->delete();
        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($user, $app) {
                new RestoreReactivatedAccountContentAction($app, $user)->execute();

                return [
                    'message' => 'Account content restoration executed',
                    'result' => true,
                    'user_id' => $user->getId(),
                    'app_id' => $app->getId(),
                ];
            },
            company: $user->company,
        );
    }
}
