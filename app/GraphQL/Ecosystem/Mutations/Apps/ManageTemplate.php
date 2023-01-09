<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Templates\Actions\CreateTemplate;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Repositories\UsersRepository;

final class ManageTemplate
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function create($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $createTemplate = new CreateTemplate(
            new TemplateInput(
                $app,
                $request['input']['name'],
                $request['input']['template'],
                null,
                auth()->user()
            )
        );

        return $createTemplate->execute();
    }
}
