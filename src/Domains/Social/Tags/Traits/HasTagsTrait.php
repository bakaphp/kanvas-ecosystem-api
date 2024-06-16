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

        $query = $this->morphToMany(ModelsTag::class, 'taggable', $dbConnection . '.tags_entities', 'entity_id', 'tags_id')
            ->using(TagEntity::class);

        return $query;
    }

    public function addTag(
        string $tag,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
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

        $this->tags()->attach($this->getId(), [
            'tags_id' => $tag->getId(),
            'users_id' => $user->getId(),
            'is_deleted' => 0,
        ]);
    }

    public function addTags(
        array $tags,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        foreach ($tags as $tag) {
            $this->addTag($tag, $app, $user, $company);
        }
    }

    public function removeTag(string $tag): void
    {
        $this->tags()->where('name', $tag)->delete();
    }

    public function removeTags(array $tags): void
    {
        $this->tags()->whereIn('name', $tags)->delete();
    }

    public function syncTags(array $tags): void
    {
        $this->tags()->detach();
        $this->addTags($tags);
    }
}
