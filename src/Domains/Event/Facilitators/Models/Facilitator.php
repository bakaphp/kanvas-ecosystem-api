<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Guild\Customers\Models\People;

class Facilitator extends BaseModel
{
    protected $table = 'facilitators';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }
}
