<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\PublicAppScopeTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property int $is_default
 * @property bool $is_open
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadStatus extends BaseModel
{
    use PublicAppScopeTrait;

    protected $table = 'leads_status';
    protected $guarded = [];

    /**
     * A created row only carries the attributes it was given, so without the default the non-null
     * GraphQL `is_open` resolves to null. Redeclared whole because it replaces BaseModel's `is_deleted`.
     */
    protected $attributes = [
        'is_deleted' => 0,
        'is_open' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
        ];
    }

    /**
     * Returns the default status for a given app+company, falling back to
     * the global default (apps_id=0, companies_id=0) when none is configured.
     */
    public static function getDefault(?AppInterface $app = null, ?CompanyInterface $company = null): self
    {
        if ($app !== null) {
            $key = $app->get('app-default-lead-status');

            if (! empty($key)) {
                try {
                    return self::where('name', $key)->firstOrFail();
                } catch (ModelNotFoundException) {
                }
            }
        }

        if ($company !== null && $app !== null) {
            $companyDefault = self::where('is_default', 1)
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->first();

            if ($companyDefault !== null) {
                return $companyDefault;
            }
        }

        return self::where('is_default', 1)
            ->where('apps_id', 0)
            ->where('companies_id', 0)
            ->firstOrFail();
    }

    /**
     * Eloquent keeps a loaded relation after the foreign key changes, so a stale one is ignored.
     */
    public static function resolveFor(Model $owner, string $relation, string $foreignKey): ?self
    {
        $statusId = (int) $owner->getAttributeValue($foreignKey);

        if ($owner->relationLoaded($relation)) {
            $loaded = $owner->getRelation($relation);

            if ($loaded !== null && (int) $loaded->getKey() === $statusId) {
                return $loaded;
            }
        }

        return $statusId > 0 ? self::query()->find($statusId) : null;
    }

    public static function isOpenFor(
        Model $owner,
        string $relation,
        string $foreignKey,
        ?CompanyInterface $company = null
    ): bool {
        $status = self::resolveFor($owner, $relation, $foreignKey);

        return (bool) $status?->is_open
            || in_array((int) $owner->getAttributeValue($foreignKey), self::legacyOpenIds($company), true);
    }

    public static function isOpenId(?int $statusId, ?CompanyInterface $company = null): bool
    {
        return in_array($statusId, self::legacyOpenIds($company), true)
            || ($statusId > 0 && (bool) self::query()->whereKey($statusId)->value('is_open'));
    }

    /**
     * The closed side is a negation, so it is two `NOT IN` in AND; an `OR` there would let a legacy-open id through.
     */
    public static function constrainToState(
        Builder $query,
        string $column,
        bool $open,
        ?CompanyInterface $company = null
    ): Builder {
        $legacy = self::legacyOpenIds($company);
        $openIds = self::query()->select('id')->where('is_open', true);

        if ($open) {
            return $query->where(
                fn (Builder $group) => $group
                    ->whereIn($column, $openIds)
                    ->when($legacy !== [], fn (Builder $withLegacy) => $withLegacy->orWhereIn($column, $legacy))
            );
        }

        return $query
            ->whereNotIn($column, $openIds)
            ->when($legacy !== [], fn (Builder $withLegacy) => $withLegacy->whereNotIn($column, $legacy));
    }

    /**
     * shortcut: the per-company `guild_open_leads_status_ids` setting predates `is_open`; drop it once
     * every tenant that sets it has the flag on its own status rows.
     *
     * @return array<int, int>
     */
    public static function legacyOpenIds(?CompanyInterface $company = null): array
    {
        if ($company === null) {
            return [];
        }

        $ids = $company->get(ConfigurationEnum::OPEN_LEADS_STATUS_IDS->value);

        if (! is_array($ids)) {
            $ids = $ids === null || $ids === '' ? [] : explode(',', (string) $ids);
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }
}
