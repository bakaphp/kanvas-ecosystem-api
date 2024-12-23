<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Models;

use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

/**
 *  class Channels.
 *  @package Kanvas\Social\Channels\Models
 *  @property int $id
 *  @property string $name
 *  @property string $slug
 *  @property string $description
 *  @property int $last_message_id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $entity_id
 *  @property int $entity_namespace
 */
class Channel extends BaseModel
{
    use UuidTrait;

    protected $table = 'channels';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        $databaseSocial = config('database.connections.social.database', 'social');

        return $this->belongsToMany(Users::class, $databaseSocial . '.channel_users', 'channel_id', 'users_id')
                ->withTimestamps()
                ->withPivot('roles_id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'entity_namespace', 'model_name')->where('apps_id', $this->apps_id);
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'channel_messages', 'channel_id', 'messages_id')
                ->withTimestamps();
    }

    public function addMessage(Message $message, ?UserInterface $user = null): void
    {
        $this->messages()->syncWithoutDetaching($message->id, [
            'users_id' => $user ? $user->getId() : $message->users_id,
        ]);
        $this->last_message_id = $message->id;
        $this->saveOrFail();
    }
}
