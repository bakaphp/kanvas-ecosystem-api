<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * A Kanvas User responsible for approving AP/AR items on behalf of this Organization (vendor/customer).
 * An Organization can have more than one approver — organizations_id/users_id live on different
 * database connections, so this is a plain belongsTo pivot model rather than a belongsToMany
 * relation, which would require a single-connection join.
 *
 * @property int $organizations_id
 * @property int $users_id
 * @property string $created_at
 */
class OrganizationApprover extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'organization_approvers';
    protected $guarded = [];

    protected $attributes = [];
    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }

    /**
     * Not deleted scope.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @return list<string>
     */
    public static function emailsFor(Organization $organization): array
    {
        return self::query()
            ->where('organizations_id', $organization->getId())
            ->with('user')
            ->get()
            ->pluck('user.email')
            ->filter(static fn (?string $email): bool => $email !== null && trim($email) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function addApproverToOrganization(Organization $organization, Users $user): OrganizationApprover
    {
        return self::firstOrCreate([
            'organizations_id' => $organization->getId(),
            'users_id' => $user->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function removeApproverFromOrganization(Organization $organization, Users $user): int
    {
        return self::query()
            ->where('organizations_id', $organization->getId())
            ->where('users_id', $user->getId())
            ->delete();
    }

    /**
     * Links an approver by email — reuses an existing Kanvas User with that email when one exists,
     * otherwise creates a minimal, unonboarded User record just to hold the identity (no company,
     * no welcome email, no workflow) so it can be matched against Slack by email like any approver.
     */
    public static function linkApproverEmail(Organization $organization, string $email): OrganizationApprover
    {
        $user = Users::query()->where('email', $email)->first() ?? self::createMinimalUser($email);

        return self::addApproverToOrganization($organization, $user);
    }

    private static function createMinimalUser(string $email): Users
    {
        $user = new Users();
        $user->email = $email;
        $user->password = Hash::make(Str::random(32));
        $user->displayname = explode('@', $email)[0];
        $user->default_company = 0;
        $user->user_active = 1;
        $user->status = 1;
        $user->saveOrFail();

        return $user;
    }
}
