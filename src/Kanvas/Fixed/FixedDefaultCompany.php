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
                  });
        }])->get();

        foreach ($apps as $app) {
            foreach ($app->usersAssociatedApps as $userAssociatedApp) {
                if (! $userAssociatedApp->user) {
                    echo "User not found for {$userAssociatedApp->users_id}\n";

                    continue;
                }
                $data = $userAssociatedApp->toArray();
                $data['companies_id'] = 0;
                $data['password'] = $userAssociatedApp->user->password;
                $data['firstname'] = $userAssociatedApp->user->firstname;
                $data['lastname'] = $userAssociatedApp->user->lastname;
                $data['displayname'] = $userAssociatedApp->user->displayname;
                $userAssociatedApp->create($data);

                echo "User {$userAssociatedApp->user->email} has been updated to default company\n";
            }
        }
    }
}
