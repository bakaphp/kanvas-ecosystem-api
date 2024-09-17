<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Traits\PublicAppScopeTrait;

class Integrations extends BaseModel
{
    use UuidTrait;
    use PublicAppScopeTrait;

    protected $table = 'integrations';

    protected $fillable = [
        'uuid',
        'apps_id',
        'name',
        'config',
        'handler'
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function integrationCompany(): HasMany
    {
        return $this->hasMany(IntegrationsCompany::class, 'integrations_id');
    }

    public function getIntegrationsByCompany(): Collection
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return $this->integrationCompany()->where('companies_id', $company->getId())->get();
    }

    public function getIntegrationStatus(): Status
    {
        //@todo Add workflow status to seeds to catch the ids on enums.

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $active = Status::getDefaultStatusByName(StatusEnum::ACTIVE->value);
        $integrations = $this->integrationCompany()->where('companies_id', $company->getId());

        if (! $integrations->exists()) {
            return Status::getDefaultStatusByName(StatusEnum::OFFLINE->value);
        }

        if ($status = $integrations->whereNot('status_id', $active->getId())->first()) {
            return $status->status;
        };

        return $active;
    }
}
