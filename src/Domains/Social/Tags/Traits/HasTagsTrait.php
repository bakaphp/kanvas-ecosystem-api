<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag;
use Kanvas\Social\Tags\Models\Tag as ModelsTag;
use Kanvas\Social\Tags\Models\TagEntity;
use Kanvas\Workflow\Enums\WorkflowEnum;

trait HasTagsTrait
{
    public function tags(): MorphToMany
    {
        $dbConnection = config('database.connections.social.database');

        $query = $this->morphToMany(ModelsTag::class, 'taggable', $dbConnection . '.tags_entities', 'entity_id', 'tags_id')
            ->using(TagEntity::class);

        return $query;
    }

    /**
     * The key tags_entities.entity_id holds — not getId(), which a composite-key model overrides.
     */
    public function taggableKey(): mixed
    {
        return $this->{$this->tags()->getParentKeyName()};
    }

    public function hasTag(array $tags): bool
    {
        if (empty($tags)) {
            return false;
        }

        return $this->tags()
            ->whereIn('name', $tags)
            ->exists();
    }

    public function hasTags(): bool
    {
        return $this->tags()->exists();
    }

    public function addTag(
        string|int $tag,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        $attached = $this->attachTag($tag, $app, $user, $company);

        if ($attached !== null) {
            $this->fireAfterAddTag($attached, $app);
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
            $this->deleteTagEntities([$tagModel->getId()]);
        }
    }

    public function removeTags(array $tags): void
    {
        $tagIds = ModelsTag::fromApp($this->app)->whereIn('name', $tags)->pluck('id');

        if ($tagIds->isNotEmpty()) {
            $this->deleteTagEntities($tagIds->all());
        }
    }

    public function syncTags(
        array $tags,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        $heldTagIds = $this->tagEntitiesQuery()->pluck('tags_id')->map(fn ($id) => (int) $id)->all();
        $this->deleteTagEntities();

        foreach (ModelsTag::normalizeNames($tags) as $tag) {
            $attached = $this->attachTag($tag, $app, $user, $company);

            // A sync re-attaches every tag it keeps; only the ones the entity did not already hold are new.
            if ($attached !== null && ! in_array($attached->getId(), $heldTagIds, true)) {
                $this->fireAfterAddTag($attached, $app);
            }
        }
    }

    /**
     * The tag, or null when it was empty or the entity already held it.
     */
    protected function attachTag(
        string|int $tag,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): ?ModelsTag {
        if (empty($tag)) {
            return null;
        }

        $app = $this->app ?? $app;
        $user = $this->user ?? $user;
        $company = $company ?? $this->company;

        $tag = new CreateTagAction(
            new Tag(
                $app,
                $user,
                $company,
                $tag
            )
        )->execute();

        // Never attach()/detach(): a custom pivot inherits the PARENT's connection, so the write lands
        // in a different transaction than every other TagEntity write on social — and lock-waits on it.
        if ($this->tags()->wherePivot('tags_id', $tag->getId())->exists()) {
            return null;
        }

        TagEntity::create([
            'tags_id' => $tag->getId(),
            'entity_id' => $this->taggableKey(),
            'taggable_type' => $this->getMorphClass(),
            'users_id' => $user->getId(),
            'is_deleted' => 0,
        ]);

        return $tag;
    }

    protected function fireAfterAddTag(ModelsTag $tag, ?AppInterface $app = null): void
    {
        if (! method_exists($this, 'fireWorkflow')) {
            return;
        }

        $this->fireWorkflow(
            WorkflowEnum::AFTER_ADD_TAG->value,
            true,
            ['tag' => $tag->name, 'tag_id' => $tag->getId(), 'app' => $this->app ?? $app]
        );
    }

    protected function tagEntitiesQuery(): Builder
    {
        return TagEntity::query()
            ->where('entity_id', $this->taggableKey())
            ->where('taggable_type', $this->getMorphClass());
    }

    /**
     * Read the ids, then delete by primary key. A ranged DELETE on the non-unique entity_id index
     * takes gap locks even when nothing matches, and the TagEntity insert that follows deadlocks
     * against any other transaction holding the same gap.
     */
    protected function deleteTagEntities(?array $tagIds = null): void
    {
        $ids = $this->tagEntitiesQuery()
            ->when($tagIds !== null, fn ($query) => $query->whereIn('tags_id', $tagIds))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            TagEntity::query()->whereIn('id', $ids)->delete();
        }
    }
}
