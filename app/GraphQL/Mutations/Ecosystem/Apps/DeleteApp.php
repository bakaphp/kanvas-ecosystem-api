<?php
declare(strict_types=1);

namespace App\GraphQL\Mutations\Ecosystem\Apps;

use Kanvas\Apps\Apps\Models\Apps;

final class DeleteApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args)
    {
        // TODO implement the resolver
        Apps::findOrFail($args['id']);
        $apps->is_deleted = 1;
        $apps->saveOrFail();
        return $apps;
    }
}
