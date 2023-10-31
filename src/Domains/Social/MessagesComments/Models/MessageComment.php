<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

class MessageComment extends BaseModel
{
    protected $table = 'message_comments';

    public function messages(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'messages_id');
    }

    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
