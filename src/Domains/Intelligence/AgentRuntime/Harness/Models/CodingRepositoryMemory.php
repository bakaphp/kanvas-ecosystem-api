<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MemoryCategoryEnum;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * One thing an agent learned about a repository.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $repo_slug
 * @property int|null $agent_id
 * @property int|null $source_session_id
 * @property string $category
 * @property string $content
 * @property string $content_hash
 * @property string $status
 * @property int $times_reported
 * @property Carbon|null $last_reported_at
 */
class CodingRepositoryMemory extends BaseModel
{
    use UuidTrait;

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_RETIRED = 'retired';

    protected $table = 'coding_repository_memories';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'last_reported_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function category(): MemoryCategoryEnum
    {
        return MemoryCategoryEnum::tryFrom($this->category) ?? MemoryCategoryEnum::GOTCHA;
    }

    /**
     * The dedupe key. Normalised so that trivially different phrasings of the same sentence collapse:
     * the same lesson is reported on nearly every run, and without this the context fills with copies.
     */
    public static function hashFor(string $content): string
    {
        $normalised = preg_replace('/[^a-z0-9 ]+/', '', mb_strtolower(trim($content))) ?? '';

        return sha1((string) preg_replace('/\s+/', ' ', $normalised));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForRepo(Builder $query, string $repoSlug): Builder
    {
        return $query->where('repo_slug', $repoSlug);
    }
}
