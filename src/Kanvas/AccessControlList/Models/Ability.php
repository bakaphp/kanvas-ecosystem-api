<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Silber\Bouncer\Database\Ability as SilberAbility;

class Ability extends SilberAbility
{
    protected $fillable = [
        'name',
        'title',
        'entity_type',
    ];
}
