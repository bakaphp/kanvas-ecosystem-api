<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Model;

class ParticipantPassRedemption extends Model
{
    protected $connection = 'event';
    protected $table = 'participant_pass_redemptions';
    protected $guarded = [];
}
