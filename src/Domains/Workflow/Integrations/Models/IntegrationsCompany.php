<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Regions\Models\Regions;
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

    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }
}
