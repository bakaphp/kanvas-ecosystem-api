<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Baka\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Events\ChannelCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\SystemModules\Models\SystemModules;

class CreateChannelAction
{
    private ?bool $allowAppWide = null;

    public function __construct(
        protected ChannelDto $channelDto
    ) {
    }

    public function execute(): Channel
    {
        $legacySystemModule = SystemModules::getLegacyNamespace($this->channelDto->entity_namespace);
        $slug = $this->channelDto->slug ?? Str::slug($this->channelDto->name);

        // Every call after the first takes this path — no lock, no transaction, one indexed read.
        $channel = $this->findChannel($slug, $legacySystemModule)
            ?? $this->createChannelOnce($slug, $legacySystemModule);

        if (! empty($this->channelDto->tags)) {
            $channel->syncTags($this->channelDto->tags);
        }

        try {
            $channel->users()->syncWithoutDetaching([
                $this->channelDto->users->id => [
                    'roles_id' => RolesRepository::getByNameFromCompany(
                        name: RolesEnums::ADMIN->value,
                        app: $this->channelDto->apps,
                    )->id,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            // User already attached by a concurrent request
        }

        return $channel;
    }

    private function allowAppWide(): bool
    {
        return $this->allowAppWide ??= (bool) $this->channelDto->apps->get(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value);
    }

    private function findChannel(string $slug, string $legacySystemModule): ?Channel
    {
        return Channel::where('apps_id', $this->channelDto->apps->id)
            ->when(! $this->allowAppWide(), fn (Builder $q): Builder => $q->where('companies_id', $this->channelDto->companies->id))
            ->where('slug', $slug)
            ->whereIn(
                'entity_namespace',
                [
                    $this->channelDto->entity_namespace,
                    $legacySystemModule,
                ],
            )
            ->where('entity_id', $this->channelDto->entity_id)
            ->first();
    }

    /**
     * Serialized on a cache lock, never on the row.
     *
     * `channels` has no unique index on the (app, company, slug, entity) identity, so a
     * `lockForUpdate()` read of a channel that does not exist yet locks the *gap* instead of a row —
     * which grants nothing (gap locks don't exclude each other) and deadlocks the following insert
     * against any parallel worker writing into the same range. Callers swallow that failure, so the
     * channel is simply lost. Same trap as `src/Domains/Connectors/WaSender/CLAUDE.md` describes.
     */
    private function createChannelOnce(string $slug, string $legacySystemModule): Channel
    {
        $create = fn (): Channel => $this->findChannel($slug, $legacySystemModule)
            ?? $this->createChannel($slug);

        try {
            return Cache::lock($this->lockKey($slug), 10)->block(5, $create);
        } catch (LockTimeoutException) {
            // A duplicate serves a contended create better than a hard failure does.
            return $create();
        }
    }

    /** Keyed on what `findChannel` actually matches on — an app-wide channel is not per-company. */
    private function lockKey(string $slug): string
    {
        return 'social:channel:' . $this->channelDto->apps->id
            . ':' . ($this->allowAppWide() ? '*' : $this->channelDto->companies->id)
            . ':' . $this->channelDto->entity_namespace
            . ':' . $this->channelDto->entity_id
            . ':' . $slug;
    }

    private function createChannel(string $slug): Channel
    {
        return DB::connection('social')->transaction(function () use ($slug): Channel {
            $channel = Channel::create([
                'apps_id' => $this->channelDto->apps->id,
                'companies_id' => $this->channelDto->companies->id,
                'slug' => $slug,
                'entity_id' => $this->channelDto->entity_id,
                'entity_namespace' => $this->channelDto->entity_namespace,
                'users_id' => $this->channelDto->users->id,
                'name' => $this->channelDto->name,
                'description' => $this->channelDto->description,
                'metadata' => $this->channelDto->metadata,
            ]);

            ChannelCreatedEvent::dispatch($channel);

            return $channel;
        });
    }
}
