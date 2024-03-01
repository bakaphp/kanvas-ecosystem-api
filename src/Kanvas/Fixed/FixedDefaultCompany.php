<?php

declare(strict_types=1);

namespace Kanvas\Fixed;

use Kanvas\Apps\Models\Apps;
use Kanvas\Fixed\Interfaces\FixedInterface;

class FixedDefaultCompany implements FixedInterface
{
    public static function execute()
    {
        $apps = Apps::with(['usersAssociatedApps' => function ($query) {
            $query->whereNull('password')
                  ->whereNotIn('users_id', function ($subquery) {
                      $subquery->select('users_id')
                               ->from('users_associated_apps')
                               ->where('companies_id', 0);
                  })
                ->where('is_deleted', 0);
        }])->get();

        foreach ($apps as $app) {
            foreach ($app->usersAssociatedApps as $userAssociatedApp) {
                if (! $userAssociatedApp->user) {
                    echo "User not found for {$userAssociatedApp->users_id}\n";

                    continue;
                }
                $userAssociatedApp->user->getAppProfile($app);

                echo "User {$userAssociatedApp->user->email} has been updated to default company\n";
            }
        }
    }
}
