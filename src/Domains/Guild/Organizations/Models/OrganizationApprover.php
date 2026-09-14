<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;
use Override;

/**
 * A Kanvas User responsible for approving AP/AR items on behalf of this Organization (vendor/customer).
 * An Organization can have more than one approver — organizations_id/users_id live on different
 * database connections, so this is a plain belongsTo pivot model rather than a belongsToMany
 * relation, which would require a single-connection join.
 *
 * The table carries no apps_id/companies_id of its own, so it is tenant-scoped ONLY by being reached
 * through an Organization that was itself resolved with getByIdFromCompanyApp(). Never query it by an
 * organizations_id taken raw from request input.
 *
 * @property int $organizations_id
 * @property int $users_id
 * @property bool $is_deleted
 */
class OrganizationApprover extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'organization_approvers';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }

    /**
     * @return list<string>
     */
    public static function emailsFor(Organization $organization): array
    {
        return self::query()
            ->where('organizations_id', $organization->getId())
            ->notDeleted()
            ->with('user')
            ->get()
            ->pluck('user.email')
            ->filter(static fn (?string $email): bool => $email !== null && trim($email) !== '')
            ->unique()
            ->values()
            ->all();
    }
}
