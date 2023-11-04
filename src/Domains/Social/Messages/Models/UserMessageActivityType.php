<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Kanvas\Social\Models\BaseModel;

class UserMessageActivityType extends BaseModel
{
    protected $table = 'users_messages_activities_types';

    protected $guarded = [];
}
