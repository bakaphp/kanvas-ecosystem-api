<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Factories\MessageTypeFactory;
use Kanvas\Social\Models\BaseModel;
use Override;

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
 *  @property ?string $message_schema = null
 */
class MessageType extends BaseModel
{
    use UuidTrait;
    use HasFactory;

    protected $table = 'message_types';

    protected $guarded = [
        'uuid',
    ];

    #[Override]
    public function casts(): array
    {
        return [
            'template' => Json::class,
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'message_types_id');
    }

    public function hasMessages(): bool
    {
        return $this->messages()->exists();
    }

    protected static function newFactory()
    {
        return MessageTypeFactory::new();
    }
}
