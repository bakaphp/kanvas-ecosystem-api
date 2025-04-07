<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Kanvas\Event\Models\BaseModel;

class ParticipantType extends BaseModel
{
    protected $table = 'participant_types';
    protected $guarded = [];

    protected $is_deleted;
}
