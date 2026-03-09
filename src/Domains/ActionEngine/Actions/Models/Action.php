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
 * @property string $icon
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

    protected function casts(): array
    {
        return [
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

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('companies_id', auth()->user()->getCurrentCompany()->getId());
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
