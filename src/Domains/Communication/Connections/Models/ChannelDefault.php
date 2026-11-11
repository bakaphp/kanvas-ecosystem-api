<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Communication\Models\BaseModel;

/**
 * The connection a company's new sends on a channel go through. A row rather than a flag on the
 * connection, because two providers can serve one channel and only one of them can be the default.
 */
class ChannelDefault extends BaseModel
{
    protected $table = 'communication_channel_defaults';

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }
}
