<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Observers\OrganizationObserver;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Override;

/**
 * Class Organization.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property ?string $address = null
 * @property int $total_employees
 */
#[ObservedBy([OrganizationObserver::class])]
class Organization extends BaseModel
{
    use DatabaseSearchableTrait;
    use HasLightHouseCache;
    use HasTagsTrait;
    use UuidTrait;

    protected $table = 'organizations';
    protected $guarded = [];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Organization';
    }

    public function peoples(): HasManyThrough
    {
        return $this->hasManyThrough(
            People::class,
            OrganizationPeople::class,
            'organizations_id',
            'id',
            'id',
            'peoples_id'
        );
    }

    public function relationships(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrganizationRelated::class,
            OrganizationRelationshipType::class,
            'organizations_id',
            'id',
            'id',
            'organizations_relations_type_id'
        );
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function addPeople(People $people): OrganizationPeople
    {
        return OrganizationPeople::firstOrCreate([
            'organizations_id' => $this->getId(),
            'peoples_id' => $people->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),

        ]);
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_organization_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'organization_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'name' => $this->name,
            'address' => $this->address,
            'total_employees' => $this->total_employees,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_COMPANY_AVATAR->getValue());

        return $this->getFileByName('photo') ?: ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
