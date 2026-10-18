<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Models\Integrations;

/**
 * @property int $id
 * @property int|null $apps_id null on rows written before the column existed
 * @property int $companies_id
 * @property int $integrations_id
 * @property int $status_id
 * @property int $region_id
 * @property array|null $config
 * @property bool $is_active
 */
class IntegrationsCompany extends BaseModel
{
    protected $table = 'integration_companies';

    protected $fillable = [
        'apps_id',
        'companies_id',
        'integrations_id',
        'status_id',
        'region_id',
        'config',
        'is_active',
    ];

    protected $attributes = [
        'is_deleted' => 0,
        'is_active' => 1,
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integrations_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'region_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(EntityIntegrationHistory::class, 'integrations_company_id');
    }

    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }

    public function isActive(bool $isActive): bool
    {
        $this->is_active = $isActive;
        $this->saveOrFail();

        return $this->is_active;
    }

    /**
     * A row with no recorded app predates app ownership. It is not this app's row, but nothing says
     * it is another app's either, so it stays manageable from any app the company lives in — which is
     * what every row allowed before — until its owner is established.
     */
    public function isManageableFrom(AppInterface $app): bool
    {
        return $this->apps_id === null || (int) $this->apps_id === (int) $app->getId();
    }

    /**
     * Get the integration company using the integration name.
     *
     * With $app the lookup is strict: only that app's row, never a row with no recorded app. Without
     * it the lookup is the pre-ownership one, kept for the connectors that have not been moved yet.
     *
     * @param Status $status Current status of the integration company
     * @param string $name name of the integration
     * @param Regions $region The region of the company integration
     */
    public static function getByIntegration(
        Companies $company,
        Status $status,
        string $name,
        Regions $region,
        ?AppInterface $app = null
    ): ?IntegrationsCompany {
        $integration = Integrations::where('name', $name)->firstOrFail();

        return IntegrationsCompany::fromCompany($company)
            ->when($app, fn ($query) => $query->fromApp($app))
            ->where('integrations_id', $integration->getId())
            ->where('status_id', $status->getId())
            ->where('region_id', $region->getId())
            ->where('is_active', 1)
            ->first();
    }
}
