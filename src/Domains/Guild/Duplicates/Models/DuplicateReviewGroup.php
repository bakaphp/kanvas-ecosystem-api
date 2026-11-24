<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $entity_type
 * @property int $canonical_id
 * @property array $member_ids
 * @property string $signature
 * @property string $reason
 * @property DuplicateReviewStatusEnum $status
 * @property int|null $resolved_by_users_id
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_target_id
 * @property bool $is_deleted
 */
class DuplicateReviewGroup extends BaseModel
{
    use UuidTrait;

    protected $table = 'duplicate_review_groups';
    protected $guarded = [];

    protected $casts = [
        'status' => DuplicateReviewStatusEnum::class,
        'is_deleted' => 'boolean',
        'member_ids' => Json::class,
        'resolved_at' => 'datetime',
    ];

    public static function signatureFor(array $memberIds): string
    {
        $memberIds = array_map('intval', $memberIds);
        sort($memberIds);

        return sha1(implode(',', $memberIds));
    }

    public function scopeWithSignature(
        Builder $query,
        int $appsId,
        int $companiesId,
        string $entityType,
        string $signature
    ): Builder {
        return $query
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('entity_type', $entityType)
            ->where('signature', $signature);
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(Users::class, 'resolved_by_users_id', 'id');
    }
}
