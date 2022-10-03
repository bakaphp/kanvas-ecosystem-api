<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;

final class DeleteApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = Apps::getById($request['id']);
        $app->softDelete();

        return $app;
    }
}
