<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Silber\Bouncer\Database\Ability;

class Abilities extends Ability
{
    protected $fillable = [
        'name',
        'title',
        'entity_type',
    ];
}
