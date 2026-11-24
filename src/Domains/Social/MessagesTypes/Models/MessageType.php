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
 *  @property mixed $templates_plura
 *  @property ?string $message_schema = null
 *  @property bool $is_public
 */
class MessageType extends BaseModel
{
    use UuidTrait;
    use HasFactory;
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }

    protected $table = 'message_types';

    /**
     * Verbs whose messages are the system's audit trail (what the agent did on a record) rather than
     * something written for the feed. A type created for one of these starts private; a caller that
     * wants a given message visible passes its own flag.
     */
    public const array PRIVATE_BY_DEFAULT_VERBS = ['system'];

    protected $guarded = [
        'uuid',
    ];

    /**
     * A created row only carries the attributes it was given, so without the default the non-null
     * GraphQL `is_public` resolves to null. Redeclared whole because it replaces BaseModel's `is_deleted`.
     */
    protected $attributes = [
        'is_deleted' => 0,
        'is_public' => true,
    ];

    #[Override]
    public function casts(): array
    {
        return [
            'template' => Json::class,
            'templates_plura' => Json::class,
            'is_public' => 'boolean',
        ];
    }

    public static function isPublicByDefaultForVerb(string $verb): bool
    {
        return ! in_array($verb, self::PRIVATE_BY_DEFAULT_VERBS, true);
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
