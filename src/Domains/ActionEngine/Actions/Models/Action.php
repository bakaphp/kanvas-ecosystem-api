<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Casts\Json;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Nevadskiy\Tree\AsTree;
use Override;

/**
 * Class Action.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $pipelines_id
 * @property int $parent_id
 * @property string path
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property mixed $icon
 * @property string $form_fields
 * @property string $form_config
 * @property int is_active
 * @property int collects_info
 * @property int is_published
 */
class Action extends BaseModel
{
    use UuidTrait;
    use AsTree;
    use SlugTrait;
    use DatabaseSearchableTrait;

    protected $table = 'actions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'icon' => Json::class,
            'form_fields' => Json::class,
            'form_config' => Json::class,
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public function companyActions(): HasMany
    {
        return $this->hasMany(CompanyAction::class, 'actions_id', 'id');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_action_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'action_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        // Mirrors the fromPublicOrCurrentApp/fromCompanyAndGlobal scopes the paginated query uses:
        // actions ship both as platform globals (apps_id/companies_id 0) and per app/company rows.
        $query = self::traitSearch($query, $callback)->whereIn('apps_id', [0, app(Apps::class)->getId()]);
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->whereIn('companies_id', [0, app(CompaniesBranches::class)->company->getId()]);
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->whereIn('companies_id', [0, $user->getCurrentCompany()->getId()]);
        }

        return $query;
    }

    public static function getBySlug(string $slug, CompanyInterface $company): ?self
    {
        return static::where('slug', $slug)
        ->where('is_deleted', StateEnums::NO->getValue())
        ->first();
    }
}
