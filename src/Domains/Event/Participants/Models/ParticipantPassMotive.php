<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Kanvas\Event\Models\BaseModel;

class ParticipantPassMotive extends BaseModel
{
    protected $table = 'participant_pass_motives';
    protected $guarded = [];

    protected $is_deleted;
}
