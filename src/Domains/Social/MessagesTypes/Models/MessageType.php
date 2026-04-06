<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
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
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }

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

    protected static function newFactory(): MessageTypeFactory
    {
        return MessageTypeFactory::new();
    }

    public function searchableAs(): string
    {
        $app = app(Apps::class);
        $customIndex = $app->get('app_custom_message_type_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'message_type_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'verb' => $this->verb,
            'apps_id' => $this->apps_id,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        return self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    }
}
