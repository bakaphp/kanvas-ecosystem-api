<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag;
use Kanvas\Social\Tags\Models\Tag as ModelsTag;
use Kanvas\Social\Tags\Models\TagEntity;

trait HasTagsTrait
{
    public function tags(): MorphToMany
    {
        $dbConnection = config('database.connections.social.database');

        $query = $this->morphToMany(ModelsTag::class, 'taggable', $dbConnection.'.tags_entities', 'entity_id', 'tags_id')
            ->using(TagEntity::class);

        return $query;
    }

    public function addTag(
        string|int $tag,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        if (empty($tag)) {
            return;
        }

        $app = $this->app ?? $app;
        $user = $this->user ?? $user;
        $company = $company ?? $this->company;

        $tag = (new CreateTagAction(
            new Tag(
                $app,
                $user,
                $company,
                $tag
            )
        ))->execute();

        // Check if the tag is already attached before syncing
        if (!$this->tags()->wherePivot('tags_id', $tag->getId())->exists()) {
            $this->tags()->attach($this->getId(), [
                'tags_id'    => $tag->getId(),
                'users_id'   => $user->getId(),
                'is_deleted' => 0,
            ]);
        }
    }

    public function addTags(
        array $tags,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        foreach ($tags as $tag) {
            if (empty($tag)) {
                continue;
            }
            $this->addTag($tag, $app, $user, $company);
        }
    }

    public function removeTag(string $tag): void
    {
        $tagModel = ModelsTag::fromApp($this->app)->where('name', $tag)->first();

        if ($tagModel) {
            TagEntity::where('entity_id', $this->getId())
            ->where('tags_id', $tagModel->getId())
            ->where('taggable_type', static::class)
            ->delete();
        }
    }

    public function removeTags(array $tags): void
    {
        $tagIds = ModelsTag::fromApp($this->app)->whereIn('name', $tags)->pluck('id');

        if ($tagIds->isNotEmpty()) {
            TagEntity::where('entity_id', $this->getId())
                ->whereIn('tags_id', $tagIds)
                ->where('taggable_type', static::class)
                ->delete();
        }
    }

    public function syncTags(array $tags): void
    {
        /**
         * if we get tags as
         * [
         *   ['name' => 'tag1'],
         *   ['name' => 'tag2'],
         * ].
         */
        $tags = array_map(
            fn ($tag) => $tag['name'] ?? $tag,
            $tags
        );

        $this->tags()->detach();
        $this->addTags($tags);
    }
}
