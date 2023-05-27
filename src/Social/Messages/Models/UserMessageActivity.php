<?php

declare(strict_types=1);


namespace Kanvas\Social\Messages\Models;

use Kanvas\Social\Models\BaseModel;

class UserMessageActivity extends BaseModel
{
    protected $table = 'user_messages_activities';

    protected $guarded = [];
}