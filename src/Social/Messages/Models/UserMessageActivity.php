<?php

declare(strict_types=1);


namespace Kanvas\Social\Messages\Models;

use Kanvas\Social\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 *  class UserMessageActivity
 *  @property int $id 
 *  @property int $user_messages_id
 *  @property int $from_entity_id
 *  @property string $entity_namespace
 *  @property string $username
 *  @property string $type 
 *  @property string $text 
 */
class UserMessageActivity extends BaseModel
{
    protected $table = 'user_messages_activities';

    protected $guarded = [];
    
    /**
     * userMessage
     *
     * @return BelongsTo
     */
    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(UserMessage::class, 'user_messages_id');
    }
}