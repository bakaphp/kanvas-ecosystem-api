<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Models\Integrations;

class IntegrationsCompany extends BaseModel
{
    protected $table = 'integration_companies';

    protected $fillable = [
        'companies_id',
        'integrations_id',
        'status_id',
        'region_id',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
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

    /**
     * Get the integration company using the integration name
     *
     * @param Companies $company
     * @param Status $status Current status of the integration company
     * @param string $name name of the integration
     * @param Region $region The region of the company integration
     * @return IntegrationsCompany
     */
    public static function getByIntegration(Companies $company, Status $status, string $name, Regions $region): ?IntegrationsCompany
    {
        $integration = Integrations::where('name', $name)->firstOrFail();

        return IntegrationsCompany::fromCompany($company)
                                ->where('integrations_id', $integration->getId())
                                ->where('status_id', $status->getId())
                                ->where('region_id', $region->getId())
                                ->first();
    }
}
