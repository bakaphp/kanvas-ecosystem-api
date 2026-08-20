<?php

declare(strict_types=1);

namespace Kanvas\Regions\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SlugTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Models\BaseModel;
use Kanvas\Traits\DefaultTrait;
use Override;

/**
 * Class Regions.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $currency_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $short_slug
 * @property ?array $settings
 * @property float|null $lat
 * @property float|null $lng
 * @property int $is_default
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Regions extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use DefaultTrait {
        getDefault as getDefaultByFlag;
    }
    use SoftDeletesTrait;

    protected $table = 'regions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'settings' => Json::class,
        ];
    }

    /**
     * A company's default region is the `default_region_id` custom field, which may point at an
     * app-global region (companies_id = 0) — the regions table itself can't express that, since
     * is_default there is app-wide. Falls back to the is_default flag when the field is unset or
     * points at a region the company can no longer see.
     */
    public static function getDefault(CompanyInterface $company, ?AppInterface $app = null): ?EloquentModel
    {
        $regionId = (int) $company->get(CustomFieldEnum::DEFAULT_REGION_ID->value);

        if ($regionId > 0) {
            $region = static::query()
                ->where('id', $regionId)
                ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $company->getId()])
                ->fromApp($app ?? app(Apps::class))
                ->notDeleted()
                ->first();

            if ($region !== null) {
                return $region;
            }
        }

        // $app stays as the caller passed it — resolving it here would add an apps_id filter the
        // flag-based lookup never had.
        return static::getDefaultByFlag($company, $app);
    }

    // Backward-compat: clients queried Region.settings as a raw JSON string before
    // it was typed as RegionSettings. Expose the encoded string so old apps don't break.
    public function getSettingsStringAttribute(): ?string
    {
        return $this->settings !== null ? json_encode($this->settings, JSON_THROW_ON_ERROR) : null;
    }

    public function currencies(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouses::class, 'regions_id');
    }

    public function defaultWarehouse(): HasOne
    {
        return $this->hasOne(Warehouses::class, 'regions_id')->where('is_default', 1);
    }

    public function hasDependencies(): bool
    {
        return $this->warehouses()->exists();
    }
}
