<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Queries\Organizations;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;

class OrganizationLeadsQuery
{
    public function getLeadsBuilder(Organization $root, array $args): Builder
    {
        return Lead::query()->where('organization_id', $root->getId());
    }
}
