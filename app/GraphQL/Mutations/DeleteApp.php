<?php
namespace App\GraphQL\Mutations;

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
