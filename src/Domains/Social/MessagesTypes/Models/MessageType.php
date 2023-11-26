<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Models;

use Baka\Traits\UuidTrait;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\MessagesTypes\Factories\MessageTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 *  class MessageType
 *  @package Kanvas\Social\MessagesTypes\Models
 *  @property int $id
 *  @property string $name
 *  @property ?string $uuid
 *  @property int $apps_id
 *  @property int $languages_id
 *  @property string $name
 *  @property string $verb
 *  @property string $template
 *  @property string $templates_plura
 */
class MessageType extends BaseModel
{
    use UuidTrait;
    use HasFactory;

    protected $table = 'message_types';

    protected $guarded = [
        'uuid',
    ];

    protected static function newFactory()
    {
        return MessageTypeFactory::new();
    }
}
