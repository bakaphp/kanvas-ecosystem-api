<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class Participant extends BaseModel
{
    protected $table = 'participants';
    protected $guarded = [];

    protected $is_deleted;

    public function themeArea(): BelongsTo
    {
        return $this->belongsTo(ThemeArea::class);
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }

    public function participantType(): BelongsTo
    {
        return $this->belongsTo(ParticipantType::class);
    }
}
