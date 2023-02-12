<?php
declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

class Participants
{
    /**
     * Like a entity.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function add(mixed $root, array $req)
    {
        print_r($req); die();
    }
}
